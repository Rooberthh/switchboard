<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rooberthh\Switchboard\Events\OutboxMessageEmitted;
use Rooberthh\Switchboard\Models\OutboxMessage;
use stdClass;

/**
 * Persist one outbox message, and announce it — once.
 *
 * The outbox's counterpart to CreateInboxMessageAction. It generates the
 * event ID and renders the envelope, once, as the body every endpoint and
 * every attempt will send (ADR-0006). With an idempotency key it inserts
 * first and recovers from the unique index, so a key already taken returns
 * the message that holds it, untouched. Only a message this call actually
 * wrote is announced, after commit.
 *
 * Nothing else happens here — no endpoints, no deliveries, no job (ADR-0007)
 * — and whether the key may be reused is not this act's decision: see
 * EmitOutboxMessageAction.
 *
 * @internal
 */
final class CreateOutboxMessageAction
{
    /**
     * @param array<string, mixed> $payload
     * @param string $eventType
     * @param string|null $idempotencyKey
     */
    public function execute(string $eventType, array $payload, ?string $idempotencyKey = null): OutboxMessage
    {
        $attributes = fn(): array => [
            'event_id' => (string) Str::uuid7(),
            'event_type' => $eventType,
            'payload' => $payload,
            'body' => self::envelope($eventType, $payload, Carbon::now()),
        ];

        $message = $idempotencyKey === null
            ? OutboxMessage::query()->create($attributes())
            : OutboxMessage::query()->createOrFirst(['idempotency_key' => $idempotencyKey], $attributes);

        if ($message->wasRecentlyCreated) {
            event(new OutboxMessageEmitted($message));
        }

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
