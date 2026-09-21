<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Inbox;

use Illuminate\Support\Facades\Log;
use Rooberthh\Switchboard\Contracts\WebhookProvider as Contract;
use Rooberthh\Switchboard\Models\InboxMessage;

/**
 * The class an integration extends: one per provider.
 *
 * A subclass names the provider, returns its verification, turns its requests
 * into inbox messages and maps event types to handlers. This class supplies
 * the rest: where the secret is read from, and what happens to an event type
 * nobody wrote a handler for.
 *
 * Generate one with `php artisan make:webhook-provider`, and register it with
 * Switchboard::provider() in a service provider's boot method.
 *
 * Public API.
 */
abstract class WebhookProvider implements Contract
{
    /**
     * Event type to the invokable class that handles it.
     *
     * @var array<string, class-string>
     */
    public array $handlers = [];

    /**
     * The secret this provider's requests are signed with. Read from
     * config('switchboard.providers.{name}.secret') by default; override it to
     * read one anywhere else — a per-tenant column, a secrets manager.
     */
    protected function secret(): string
    {
        return (string) config('switchboard.providers.' . static::name() . '.secret', '');
    }

    /**
     * Logged rather than dropped, and rather than thrown: an event type you
     * have not written a handler for is news, not a failure. Override freely.
     *
     * @param InboxMessage $message
     */
    public function unhandled(InboxMessage $message): void
    {
        Log::warning('Switchboard has no handler for this event type.', [
            'provider' => $message->provider,
            'event_type' => $message->event_type,
            'event_id' => $message->event_id,
        ]);
    }
}
