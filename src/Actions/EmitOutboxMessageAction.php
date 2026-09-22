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
     * @param string|null $idempotencyKey
     *
     * @throws InvalidOutboxMessage
     */
    public function execute(string $eventType, array $payload, ?string $idempotencyKey = null): OutboxMessage
    {
        if (trim($eventType) === '') {
            throw InvalidOutboxMessage::blankEventType();
        }

        if ($idempotencyKey === null) {
            return $this->announce(OutboxMessage::query()->create($this->attributes($eventType, $payload)));
        }

        if (trim($idempotencyKey) === '') {
            throw InvalidOutboxMessage::blankIdempotencyKey();
        }

        return $this->claim($eventType, $payload, $idempotencyKey);
    }

    /**
     * Insert first and recover from the unique index, rather than reading
     * before writing, so two emits racing with one key make one message.
     *
     * A key only counts for the idempotency window. One older than that is
     * freed here, by the emit that wants it, so no sweep has to expire keys.
     *
     * @param array<string, mixed> $payload
     * @param string $eventType
     * @param string $key
     */
    private function claim(string $eventType, array $payload, string $key): OutboxMessage
    {
        $message = $this->firstOrWrite($eventType, $payload, $key);

        if ($message->wasRecentlyCreated) {
            return $this->announce($message);
        }

        if ($message->created_at->lessThan(Carbon::now()->subSeconds(self::window()))) {
            OutboxMessage::query()
                ->whereKey($message->getKey())
                ->where('idempotency_key', $key)
                ->update(['idempotency_key' => null]);

            $message = $this->firstOrWrite($eventType, $payload, $key);

            if ($message->wasRecentlyCreated) {
                return $this->announce($message);
            }
        }

        // The same key with different content is a bug in the caller's key
        // scheme. Returning the first message would silently drop the second.
        if ($message->event_type !== $eventType || $message->payload !== self::normalize($payload)) {
            throw InvalidOutboxMessage::idempotencyKeyReused($key, $message->event_id);
        }

        return $message;
    }

    /**
     * @param array<string, mixed> $payload
     * @param string $eventType
     * @param string $key
     */
    private function firstOrWrite(string $eventType, array $payload, string $key): OutboxMessage
    {
        return OutboxMessage::query()->createOrFirst(
            ['idempotency_key' => $key],
            fn(): array => $this->attributes($eventType, $payload),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param string $eventType
     * @return array<string, mixed>
     */
    private function attributes(string $eventType, array $payload): array
    {
        return [
            'event_id' => (string) Str::uuid7(),
            'event_type' => $eventType,
            'payload' => $payload,
            'body' => self::envelope($eventType, $payload, Carbon::now()),
        ];
    }

    private function announce(OutboxMessage $message): OutboxMessage
    {
        event(new OutboxMessageEmitted($message));

        return $message;
    }

    /**
     * The payload as it reads back from storage, so a comparison is between
     * like and like.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function normalize(array $payload): array
    {
        /** @var array<string, mixed> */
        return json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function window(): int
    {
        return (int) config('switchboard.outbox.idempotency_window', 86400);
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
