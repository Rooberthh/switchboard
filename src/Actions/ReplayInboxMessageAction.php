<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Actions;

use Rooberthh\Switchboard\Exceptions\IllegalTransition;
use Rooberthh\Switchboard\Jobs\ProcessInboxMessage;
use Rooberthh\Switchboard\Models\InboxMessage;
use Throwable;

/**
 * Return one failed message to unprocessed and queue it again.
 *
 * Only a failed message is eligible: re-running one that succeeded would
 * repeat side effects the application already performed. Replay is re-run,
 * never re-verify — a stored message is a normalized record and its signature
 * cannot be recomputed. See
 * docs/adr/0003-inbox-messages-are-normalized-records.md.
 *
 * The failure is held inside the act for the length of the dispatch, so a
 * queue that is part of the outage too puts the message back rather than
 * leaving it unprocessed with no job to process it and its diagnosis erased.
 * No caller ever holds failed_at or last_error.
 *
 * @internal
 */
final class ReplayInboxMessageAction
{
    /**
     * @throws IllegalTransition when the message has not failed
     * @param InboxMessage $message
     */
    public function execute(InboxMessage $message): void
    {
        if (! $message->isFailed()) {
            throw IllegalTransition::notFailed($message);
        }

        $failedAt = $message->failed_at;
        $lastError = $message->last_error;

        $message->forceFill([
            'failed_at' => null,
            'last_error' => null,
        ])->save();

        try {
            ProcessInboxMessage::dispatch($message->id);
        } catch (Throwable $e) {
            $message->forceFill([
                'failed_at' => $failedAt,
                'last_error' => $lastError,
            ])->save();

            throw $e;
        }
    }
}
