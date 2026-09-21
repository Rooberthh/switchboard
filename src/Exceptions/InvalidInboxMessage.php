<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Exceptions;

use InvalidArgumentException;

/**
 * A provider read something out of a request that cannot make an inbox message.
 */
final class InvalidInboxMessage extends InvalidArgumentException
{
    public static function blankEventId(): self
    {
        return new self(
            'A provider must supply an event id: it is what makes an inbox message idempotent. '
            . "A blank one would deduplicate every event the provider sends onto a single row.",
        );
    }

    public static function blankEventType(): self
    {
        return new self('A provider must supply an event type: it is what routes a message to a handler.');
    }

    public static function blankProvider(): self
    {
        return new self('An inbox message must belong to a provider: it is half of what a message is idempotent on.');
    }

    public static function providerMismatch(string $provider, string $normalized): self
    {
        return new self(
            "The Switchboard provider [{$provider}] normalized a message for [{$normalized}]. "
            . 'Pass provider: static::name() to InboxMessageData: a message filed under another '
            . "provider's name would share that provider's event ids.",
        );
    }
}
