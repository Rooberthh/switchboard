<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Console;

use Illuminate\Console\Command;
use Rooberthh\Switchboard\Actions\Inbox\ReplayAction;
use Rooberthh\Switchboard\Inbox\InboxMessages;
use Rooberthh\Switchboard\Models\InboxMessage;

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
        $replay = app(ReplayAction::class);

        // Paged by id, not by offset: clearing failed_at removes the row from
        // this query, so offset paging would step over as many messages as it
        // replayed.
        $query->eachById(function (InboxMessage $message) use (&$replayed, $replay): void {
            $replay->execute($message);

            $replayed++;
        });

        $this->components->info("Re-dispatched {$replayed} failed inbox " . str('message')->plural($replayed) . '.');

        return self::SUCCESS;
    }
}
