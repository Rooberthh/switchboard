<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Rooberthh\Switchboard\Contracts\Driver;
use Rooberthh\Switchboard\Contracts\Handler;
use Rooberthh\Switchboard\Exceptions\UnknownHandler;
use Rooberthh\Switchboard\Exceptions\UnknownProvider;
use Rooberthh\Switchboard\Http\Controllers\InboxController;

/**
 * Switchboard's entry point for behaviour, configured from a service
 * provider's boot method. Configuration holds data; this class holds
 * behaviour. There is deliberately no facade.
 *
 * Public API.
 */
final class Switchboard
{
    /** @var array<string, Driver|Closure(): Driver|class-string<Driver>> */
    private static array $drivers = [];

    /** @var array<string, Handler|Closure(): Handler|class-string<Handler>> */
    private static array $handlers = [];

    /**
     * Register the driver that reads a provider.
     *
     * @param  Driver|Closure(): Driver|class-string<Driver>  $driver
     * @param string $provider
     */
    public static function extend(string $provider, Driver|Closure|string $driver): void
    {
        self::$drivers[$provider] = $driver;
    }

    public static function hasDriver(string $provider): bool
    {
        return isset(self::$drivers[$provider]);
    }

    /**
     * Resolved per request rather than cached, so a driver registered as a
     * class name may depend on request- or tenant-scoped state even in a
     * long-lived worker.
     *
     * @param  string  $provider
     *
     * @throws UnknownProvider
     */
    public static function driver(string $provider): Driver
    {
        if (! isset(self::$drivers[$provider])) {
            throw UnknownProvider::for($provider);
        }

        $driver = self::$drivers[$provider];

        return match (true) {
            $driver instanceof Driver => $driver,
            $driver instanceof Closure => $driver(),
            default => app($driver),
        };
    }

    /**
     * Register the handler that acts on a provider's messages. It runs on the
     * queue, never during the request that delivered the message.
     *
     * @param  Handler|Closure(): Handler|class-string<Handler>  $handler
     * @param string $provider
     */
    public static function handledBy(string $provider, Handler|Closure|string $handler): void
    {
        self::$handlers[$provider] = $handler;
    }

    public static function hasHandler(string $provider): bool
    {
        return isset(self::$handlers[$provider]);
    }

    /**
     * Resolved per message rather than cached, so a handler is free to hold
     * per-message state.
     *
     * @throws UnknownHandler
     * @param string $provider
     */
    public static function handler(string $provider): Handler
    {
        if (! isset(self::$handlers[$provider])) {
            throw UnknownHandler::for($provider);
        }

        $handler = self::$handlers[$provider];

        return match (true) {
            $handler instanceof Handler => $handler,
            $handler instanceof Closure => $handler(),
            default => app($handler),
        };
    }

    /**
     * The providers a driver has been registered for.
     *
     * @return list<string>
     */
    public static function providers(): array
    {
        return array_keys(self::$drivers);
    }

    /**
     * Mount the endpoint a provider posts to.
     *
     * Defaults to POST /webhooks/{provider}. The returned route can be
     * decorated further like any other.
     *
     * @param  array<int, string>|string  $middleware
     * @param string $provider
     * @param ?string $path
     *
     * @throws UnknownProvider when no driver is registered for the provider
     */
    public static function route(string $provider, ?string $path = null, array|string $middleware = []): Route
    {
        if (! self::hasDriver($provider)) {
            throw UnknownProvider::for($provider);
        }

        $path ??= trim((string) config('switchboard.inbox.path', 'webhooks'), '/') . '/' . $provider;

        return RouteFacade::post($path, InboxController::class)
            ->defaults('provider', $provider)
            ->middleware($middleware)
            ->name("switchboard.inbox.{$provider}");
    }

    /**
     * Forget every registration. Intended for tests.
     */
    public static function flush(): void
    {
        self::$drivers = [];
        self::$handlers = [];
    }
}
