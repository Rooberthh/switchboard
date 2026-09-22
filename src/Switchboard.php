<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Rooberthh\Switchboard\Actions\EmitOutboxMessageAction;
use Rooberthh\Switchboard\Contracts\WebhookProvider;
use Rooberthh\Switchboard\Exceptions\InvalidProvider;
use Rooberthh\Switchboard\Exceptions\UnknownProvider;
use Rooberthh\Switchboard\Http\Controllers\InboxController;
use Rooberthh\Switchboard\Models\OutboxMessage;

/**
 * Switchboard's entry point. Providers are registered here from a service
 * provider's boot method, and outbox messages are emitted here. Configuration
 * holds data; a provider class holds behaviour. There is deliberately no
 * facade.
 *
 * Public API.
 */
final class Switchboard
{
    /** @var array<string, class-string<WebhookProvider>> */
    private static array $providers = [];

    /**
     * Register a provider and mount the endpoint it posts to.
     *
     * Defaults to POST /webhooks/{name}. The returned route can be decorated
     * further like any other, such as with middleware.
     *
     * Registered from boot() rather than a route file because route files do
     * not run once routes are cached, and the queue needs the provider too.
     * Nothing is built here: the name is static, so a provider with expensive
     * dependencies costs nothing until a delivery or a message needs it.
     *
     * @param  class-string<WebhookProvider>  $provider
     * @param  string|null  $path
     *
     * @throws InvalidProvider when the class is not a provider, or its name is taken
     */
    public static function provider(string $provider, ?string $path = null): Route
    {
        if (! is_subclass_of($provider, WebhookProvider::class)) {
            throw InvalidProvider::notAProvider($provider);
        }

        $name = $provider::name();

        if (isset(self::$providers[$name])) {
            throw InvalidProvider::alreadyRegistered($name, $provider, self::$providers[$name]);
        }

        self::$providers[$name] = $provider;

        $path ??= trim((string) config('switchboard.inbox.path', 'webhooks'), '/') . '/' . $name;

        // The name rather than the class travels with the route, so a cached
        // route resolves whatever class is registered under it.
        return RouteFacade::post($path, InboxController::class)
            ->defaults('provider', $name)
            ->name("switchboard.inbox.{$name}");
    }

    /**
     * Build the provider registered under a name, fresh each time, so it may
     * depend on request- or tenant-scoped state even in a long-lived worker.
     *
     * @param  string  $name
     *
     * @throws UnknownProvider
     */
    public static function resolve(string $name): WebhookProvider
    {
        if (! isset(self::$providers[$name])) {
            throw UnknownProvider::for($name);
        }

        return app(self::$providers[$name]);
    }

    /**
     * The names providers have been registered under.
     *
     * @return list<string>
     */
    public static function providers(): array
    {
        return array_keys(self::$providers);
    }

    /**
     * Emit an outbox message: write it, and nothing else.
     *
     * Call it inside the transaction that makes the event true, and the
     * message commits or rolls back with your own writes; call it outside one
     * and it commits on its own. Either way it performs no HTTP and queues
     * nothing — the scheduled relay turns it into deliveries once it has
     * committed.
     *
     * @param  array<string, mixed>  $payload
     * @param  string  $eventType
     */
    public static function emit(string $eventType, array $payload = []): OutboxMessage
    {
        return app(EmitOutboxMessageAction::class)->execute($eventType, $payload);
    }

    /**
     * Forget every registration. Intended for tests.
     */
    public static function flush(): void
    {
        self::$providers = [];
    }
}
