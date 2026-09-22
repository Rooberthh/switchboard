<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rooberthh\Switchboard\Events\OutboxMessageEmitted;
use Rooberthh\Switchboard\Exceptions\InvalidOutboxMessage;
use Rooberthh\Switchboard\Models\OutboxMessage;
use stdClass;

/**
 * Write one outbox message, and nothing else.
 *
 * No endpoints are looked up, no deliveries created and no job queued: the
 * relay does all of that once the message has committed (ADR-0007). So this is
 * a single insert, the cheapest thing to put inside someone else's
 * transaction — and whether there is a transaction is the caller's decision.
 *
 * The envelope is rendered here, once, and stored as the body every endpoint
 * and every attempt will send (ADR-0006).
 *
 * @internal
 */
final class EmitOutboxMessageAction
{
    /**
     * @param array<string, mixed> $payload
     * @param string $eventType
     *
     * @throws InvalidOutboxMessage
     */
    public function execute(string $eventType, array $payload): OutboxMessage
    {
        if (trim($eventType) === '') {
            throw InvalidOutboxMessage::blankEventType();
        }

        $message = OutboxMessage::query()->create([
            'event_id' => (string) Str::uuid7(),
            'event_type' => $eventType,
            'payload' => $payload,
            'body' => self::envelope($eventType, $payload, Carbon::now()),
        ]);

        event(new OutboxMessageEmitted($message));

        return $message;
    }

    /**
     * The Standard Webhooks envelope: what happened, when, and its payload.
     *
     * @param array<string, mixed> $payload
     * @param string $eventType
     * @param Carbon $emittedAt
     */
    private static function envelope(string $eventType, array $payload, Carbon $emittedAt): string
    {
        return json_encode([
            'type' => $eventType,
            'timestamp' => $emittedAt->toIso8601ZuluString('microsecond'),
            // An empty payload is still an object on the wire, never [].
            'data' => $payload === [] ? new stdClass() : $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
