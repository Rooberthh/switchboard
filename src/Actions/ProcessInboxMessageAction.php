<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Actions;

use Rooberthh\Switchboard\Events\InboxMessageProcessed;
use Rooberthh\Switchboard\Exceptions\IllegalTransition;
use Rooberthh\Switchboard\Models\InboxMessage;

/**
 * Record that a handler succeeded.
 *
 * Distinct from processing itself, which is the handler running on the queue:
 * the handler does the work, this is the record of it. Announcing it is part
 * of the act, so a message cannot become processed quietly.
 *
 * Deliberately tolerant of a repeat. Two workers can both read a message as
 * unprocessed and both run the handler — see
 * docs/adr/0004-the-inbox-relay-infers-staleness-from-age.md — and marking an
 * already processed message processed again is idempotent, not illegal.
 *
 * @internal
 */
final class ProcessInboxMessageAction
{
    /**
     * @throws IllegalTransition when the message has already failed
     * @param InboxMessage $message
     */
    public function execute(InboxMessage $message): void
    {
        if ($message->isFailed()) {
            throw IllegalTransition::alreadyFailed($message);
        }

        $message->forceFill(['processed_at' => now()])->save();

        event(new InboxMessageProcessed($message));
    }
}
