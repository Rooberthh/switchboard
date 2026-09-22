<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Actions;

use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Exceptions\InvalidOutboxMessage;
use Rooberthh\Switchboard\Models\OutboxMessage;

/**
 * Emit: the rules around writing an outbox message.
 *
 * What an emit may contain, and what an idempotency key means — it holds for
 * the idempotency window, is freed by the emit that wants it once that has
 * passed, and may not be reused for different content. The write itself is
 * CreateOutboxMessageAction's.
 *
 * Emitting is a single insert, the cheapest thing to put inside someone
 * else's transaction — and whether there is a transaction is the caller's
 * decision.
 *
 * @internal
 */
final class EmitOutboxMessageAction
{
    public function __construct(private readonly CreateOutboxMessageAction $create) {}

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
            return $this->create->execute($eventType, $payload);
        }

        if (trim($idempotencyKey) === '') {
            throw InvalidOutboxMessage::blankIdempotencyKey();
        }

        $message = $this->create->execute($eventType, $payload, $idempotencyKey);

        if ($message->wasRecentlyCreated) {
            return $message;
        }

        // A key only counts for the idempotency window. One older than that
        // is freed here, by the emit that wants it, so no sweep has to expire
        // keys.
        if ($message->created_at->lessThan(Carbon::now()->subSeconds(self::window()))) {
            OutboxMessage::query()
                ->whereKey($message->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->update(['idempotency_key' => null]);

            $message = $this->create->execute($eventType, $payload, $idempotencyKey);

            if ($message->wasRecentlyCreated) {
                return $message;
            }
        }

        // The same key with different content is a bug in the caller's key
        // scheme. Returning the first message would silently drop the second.
        if ($message->event_type !== $eventType || $message->payload !== self::normalize($payload)) {
            throw InvalidOutboxMessage::idempotencyKeyReused($idempotencyKey, $message->event_id);
        }

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
}
