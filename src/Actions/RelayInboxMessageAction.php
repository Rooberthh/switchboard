<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Actions;

use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Exceptions\IllegalTransition;
use Rooberthh\Switchboard\Jobs\ProcessInboxMessage;
use Rooberthh\Switchboard\Models\InboxMessage;
use Throwable;

/**
 * Relay one stranded message: mark it, queue it, and put the mark back if the
 * queue will not take it.
 *
 * The act owns the ordering because getting it wrong is expensive either way.
 * Marked before dispatching, so a sweep that dies partway cannot come back and
 * relay the same message a second time; unmarked if the dispatch throws, so a
 * queue that is still part of the outage leaves the message exactly as it was
 * for the next sweep. See
 * docs/adr/0004-the-inbox-relay-infers-staleness-from-age.md.
 *
 * relayed_at is bookkeeping, not a lifecycle state: a relayed message is still
 * unprocessed. Nothing is announced, because nothing has happened to the
 * message that an application did not already know about.
 *
 * @internal
 */
final class RelayInboxMessageAction
{
    /**
     * @throws IllegalTransition when the message has already been relayed once
     * @param InboxMessage $message
     */
    public function execute(InboxMessage $message): void
    {
        if ($message->relayed_at !== null) {
            throw IllegalTransition::alreadyRelayed($message);
        }

        $message->forceFill(['relayed_at' => Carbon::now()])->save();

        try {
            ProcessInboxMessage::dispatch($message->id);
        } catch (Throwable $e) {
            $message->forceFill(['relayed_at' => null])->save();

            throw $e;
        }
    }
}
