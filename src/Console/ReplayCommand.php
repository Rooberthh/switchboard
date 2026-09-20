<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Console;

use Illuminate\Console\Command;
use Rooberthh\Switchboard\Inbox\InboxMessages;
use Rooberthh\Switchboard\Jobs\ProcessInboxMessage;
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

        if (is_string($provider) && $provider !== '') {
            $query->forProvider($provider);
        }

        $replayed = 0;

        // Only failed messages are eligible: re-running one that succeeded
        // would repeat side effects the application already performed.
        $query->orderBy('id')->each(function (InboxMessage $message) use (&$replayed): void {
            $message->forceFill([
                'failed_at' => null,
                'last_error' => null,
            ])->save();

            ProcessInboxMessage::dispatch($message->id);

            $replayed++;
        });

        $this->components->info("Re-dispatched {$replayed} failed inbox " . str('message')->plural($replayed) . '.');

        return self::SUCCESS;
    }
}
