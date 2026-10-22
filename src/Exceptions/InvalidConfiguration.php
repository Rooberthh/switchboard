<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Exceptions;

use InvalidArgumentException;

/**
 * A combination of config values Switchboard cannot work with. Thrown rather
 * than guessed around, because a guess here is a silent change in behaviour.
 */
final class InvalidConfiguration extends InvalidArgumentException
{
    public static function unlimitedTriesWithoutStaleAfter(): self
    {
        return new self(
            'switchboard.inbox.tries is 0, which the queue treats as unlimited attempts, so a message may '
            . 'legitimately stay unprocessed forever and switchboard:relay cannot derive when one is lost. '
            . 'Set switchboard.inbox.stale_after, or give switchboard.inbox.tries a limit.',
        );
    }
}
