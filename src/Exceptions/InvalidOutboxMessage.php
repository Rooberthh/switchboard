<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Exceptions;

use InvalidArgumentException;

/**
 * An emit that cannot make an outbox message.
 */
final class InvalidOutboxMessage extends InvalidArgumentException
{
    public static function blankEventType(): self
    {
        return new self('An outbox message needs an event type: it is what endpoints subscribe to.');
    }

    public static function blankIdempotencyKey(): self
    {
        return new self('An idempotency key cannot be blank. Leave it out to emit without one.');
    }

    public static function idempotencyKeyReused(string $key, string $eventId): self
    {
        return new self(
            "The idempotency key [{$key}] was already used, within the idempotency window, for message [{$eventId}] "
            . 'with a different event type or payload. An idempotency key must identify one thing that happened.',
        );
    }
}
