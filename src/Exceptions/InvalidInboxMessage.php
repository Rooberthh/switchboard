<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Exceptions;

use InvalidArgumentException;

/**
 * A driver read something out of a request that cannot make an inbox message.
 */
final class InvalidInboxMessage extends InvalidArgumentException
{
    public static function blankEventId(): self
    {
        return new self(
            'A driver must supply an event id: it is what makes an inbox message idempotent. '
            . "A blank one would deduplicate every event the provider sends onto a single row.",
        );
    }

    public static function blankEventType(): self
    {
        return new self('A driver must supply an event type: it is what routes a message to a handler method.');
    }

    public static function blankProvider(): self
    {
        return new self('An inbox message must belong to a provider: it is half of what a message is idempotent on.');
    }

    public static function providerMismatch(string $route, string $driver): self
    {
        return new self(
            "The driver registered for [{$route}] normalized a message for [{$driver}]. "
            . 'A driver must supply the key it is registered under: a message filed under another '
            . "provider's name would share that provider's event ids.",
        );
    }
}
