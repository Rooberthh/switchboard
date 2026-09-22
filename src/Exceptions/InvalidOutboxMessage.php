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
}
