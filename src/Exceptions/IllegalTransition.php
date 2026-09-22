<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Exceptions;

use LogicException;
use Rooberthh\Switchboard\Models\Delivery;
use Rooberthh\Switchboard\Models\InboxMessage;

/**
 * An act was asked to move a message somewhere the lifecycle does not go.
 *
 * Processed and failed are terminal and mutually exclusive: nothing may turn a
 * failed message into a processed one, or the other way round, without going
 * through replay. Acts that are merely repeated — a duplicate job marking an
 * already processed message processed again — are idempotent, not illegal.
 */
final class IllegalTransition extends LogicException
{
    public static function alreadyFailed(InboxMessage $message): self
    {
        return new self(
            "Inbox message [{$message->id}] has already failed and cannot be marked processed. "
            . 'Replay it with switchboard:replay, which returns it to unprocessed first.',
        );
    }

    public static function alreadyProcessed(InboxMessage $message): self
    {
        return new self(
            "Inbox message [{$message->id}] has already been processed and cannot be marked failed. "
            . 'A handler that succeeded must not be recorded as a failure.',
        );
    }

    public static function alreadyRelayed(InboxMessage $message): self
    {
        return new self(
            "Inbox message [{$message->id}] has already been relayed once and must not be relayed again. "
            . 'Schedule switchboard:relay with withoutOverlapping() so two sweeps cannot race.',
        );
    }

    public static function notFailed(InboxMessage $message): self
    {
        return new self(
            "Inbox message [{$message->id}] has not failed, so there is nothing to replay. "
            . 'Re-running a message that succeeded would repeat side effects the application already performed.',
        );
    }

    public static function deliveryNotFailed(Delivery $delivery): self
    {
        return new self(
            "Delivery [{$delivery->id}] has not failed, so there is nothing to replay. "
            . 'Only a failed delivery can be replayed: sending a delivered one again would repeat what its receiver already has.',
        );
    }
}
