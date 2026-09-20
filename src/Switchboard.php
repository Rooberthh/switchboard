<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Rooberthh\Switchboard\Contracts\Driver;
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

    /** @var array<string, Driver> */
    private static array $resolved = [];

    /**
     * Register the driver that reads a provider.
     *
     * @param  Driver|Closure(): Driver|class-string<Driver>  $driver
     * @param string $provider
     */
    public static function extend(string $provider, Driver|Closure|string $driver): void
    {
        self::$drivers[$provider] = $driver;

        unset(self::$resolved[$provider]);
    }

    public static function hasDriver(string $provider): bool
    {
        return isset(self::$drivers[$provider]);
    }

    /**
     * @throws UnknownProvider
     * @param string $provider
     */
    public static function driver(string $provider): Driver
    {
        if (isset(self::$resolved[$provider])) {
            return self::$resolved[$provider];
        }

        if (! isset(self::$drivers[$provider])) {
            throw UnknownProvider::for($provider);
        }

        $driver = self::$drivers[$provider];

        return self::$resolved[$provider] = match (true) {
            $driver instanceof Driver => $driver,
            $driver instanceof Closure => $driver(),
            default => app($driver),
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
        self::$resolved = [];
    }
}
