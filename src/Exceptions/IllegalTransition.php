<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Exceptions;

use LogicException;
use Rooberthh\Switchboard\Models\Delivery;

/**
 * A delivery was asked to move somewhere its lifecycle does not go.
 *
 * Only a failed delivery can be replayed: delivered is final, and sending a
 * delivered one again would repeat what its receiver already has. Inbox
 * messages never throw this; their transitions are claims that simply do not
 * happen when the message is not in the state they move from (ADR-0009).
 */
final class IllegalTransition extends LogicException
{
    public static function deliveryNotFailed(Delivery $delivery): self
    {
        return new self(
            "Delivery [{$delivery->id}] has not failed, so there is nothing to replay. "
            . 'Only a failed delivery can be replayed: sending a delivered one again would repeat what its receiver already has.',
        );
    }
}
