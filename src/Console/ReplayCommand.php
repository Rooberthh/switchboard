<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Console;

use Illuminate\Console\Command;
use Rooberthh\Switchboard\Inbox\InboxMessages;
use Rooberthh\Switchboard\Jobs\ProcessInboxMessage;
use Rooberthh\Switchboard\Models\InboxMessage;
use Throwable;

/**
 * Re-run messages that failed, without asking the provider to resend.
 *
 * Replay is re-run, never re-verify: a stored message is a normalized record
 * and its signature cannot be recomputed. See
 * docs/adr/0003-inbox-messages-are-normalized-records.md.
 *
 * @internal
 */
final class ReplayCommand extends Command
{
    protected $signature = 'switchboard:replay
                            {--provider= : Only replay messages from this provider}';

    protected $description = 'Re-dispatch failed inbox messages';

    public function handle(): int
    {
        $query = InboxMessages::query()->failed();

        $provider = $this->option('provider');

        if (is_string($provider)) {
            if (trim($provider) === '') {
                // Not the same as leaving it off: a script whose variable went
                // missing must not replay every provider's failures.
                $this->components->error('The --provider option was given without a value.');

                return self::FAILURE;
            }

            $query->forProvider($provider);
        }

        $replayed = 0;

        // Only failed messages are eligible: re-running one that succeeded
        // would repeat side effects the application already performed.
        //
        // Paged by id, not by offset: clearing failed_at removes the row from
        // this query, so offset paging would step over as many messages as it
        // replayed.
        $query->eachById(function (InboxMessage $message) use (&$replayed): void {
            $failedAt = $message->failed_at;
            $lastError = $message->last_error;

            $message->forceFill([
                'failed_at' => null,
                'last_error' => null,
            ])->save();

            try {
                ProcessInboxMessage::dispatch($message->id);
            } catch (Throwable $e) {
                // The queue is part of the outage too. Put the message back
                // rather than leaving it unprocessed with no job to process
                // it and its diagnosis erased.
                $message->forceFill([
                    'failed_at' => $failedAt,
                    'last_error' => $lastError,
                ])->save();

                throw $e;
            }

            $replayed++;
        });

        $this->components->info("Re-dispatched {$replayed} failed inbox " . str('message')->plural($replayed) . '.');

        return self::SUCCESS;
    }
}
