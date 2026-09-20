<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Inbox;

use Illuminate\Support\Facades\Log;
use Rooberthh\Switchboard\Contracts\Handler as HandlerContract;
use Rooberthh\Switchboard\Models\InboxMessage;

/**
 * One handler per provider, one method per event type.
 *
 * Event types are mapped to methods by an explicit registry rather than by
 * deriving a method name from the event type: derivation collides silently —
 * "customer.subscription.created" and "customer.subscriptionCreated" derive to
 * the same name — and routing two different events to the same code is the
 * failure mode worth designing out. An application that wants derivation can
 * have it by overriding {@see methodFor()}.
 *
 * Public API.
 */
abstract class Handler implements HandlerContract
{
    /**
     * Event type to method name.
     *
     * @var array<string, string>
     */
    protected array $handles = [];

    final public function handle(InboxMessage $message): void
    {
        $method = $this->methodFor($message);

        if ($method !== null && method_exists($this, $method)) {
            $this->{$method}($message);

            return;
        }

        $this->unhandled($message);
    }

    /**
     * Which method handles this message, if any. Override to map event types
     * to methods some other way.
     * @param InboxMessage $message
     */
    protected function methodFor(InboxMessage $message): ?string
    {
        return $this->handles[$message->event_type] ?? null;
    }

    /**
     * What to do with an event type this handler has no method for. Logged
     * rather than dropped, and rather than thrown: an event type you have not
     * written code for is news, not a failure. Override it freely.
     * @param InboxMessage $message
     */
    protected function unhandled(InboxMessage $message): void
    {
        Log::warning('Switchboard has no handler method for this event type.', [
            'provider' => $message->provider,
            'event_type' => $message->event_type,
            'event_id' => $message->event_id,
        ]);
    }
}
