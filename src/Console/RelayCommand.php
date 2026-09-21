<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Rooberthh\Switchboard\Actions\RelayInboxMessageAction;
use Rooberthh\Switchboard\Inbox\InboxMessages;
use Rooberthh\Switchboard\Inbox\Staleness;
use Rooberthh\Switchboard\Models\InboxMessage;

/**
 * Queue the messages whose jobs were never queued.
 *
 * A message is persisted before it is dispatched, so a queue that was down at
 * that moment leaves the message stored and unprocessed forever: the provider's
 * retry is deduplicated and dispatches nothing, and replay only looks at
 * failures. The relay is what closes that gap, and what makes processing
 * at-least-once rather than best-effort.
 *
 * It relays a message at most once. Without that, an outage amplifies: every
 * run of a scheduled relay would queue another copy of every stranded message,
 * and ProcessInboxMessage's guard is a read, not a lock.
 *
 * @internal
 */
final class RelayCommand extends Command
{
    protected $signature = 'switchboard:relay
                            {--provider= : Only relay messages from this provider}
                            {--limit=1000 : How many messages to relay in one run}
                            {--stale-after= : Seconds a message may be unprocessed before it counts as stranded}';

    protected $description = 'Queue inbox messages whose processing job was never queued';

    public function handle(): int
    {
        $provider = $this->option('provider');

        if (is_string($provider) && trim($provider) === '') {
            // Not the same as leaving it off: a script whose variable went
            // missing must not sweep every provider.
            $this->components->error('The --provider option was given without a value.');

            return self::FAILURE;
        }

        $query = InboxMessages::query()->stale($this->cutoff());

        if (is_string($provider)) {
            $query->forProvider($provider);
        }

        $relayed = $this->relay($query->limit($this->limit()));

        $this->components->info("Relayed {$relayed} stranded inbox " . str('message')->plural($relayed) . '.');

        $this->warnAboutMessagesNothingConsumed();

        return self::SUCCESS;
    }

    /**
     * @param  Builder<InboxMessage>  $query
     */
    private function relay(Builder $query): int
    {
        $relayed = 0;
        $relay = app(RelayInboxMessageAction::class);

        // Paged by id rather than by offset, like replay: the predicate this
        // pages through is one the sweep itself changes.
        $query->eachById(function (InboxMessage $message) use (&$relayed, $relay): void {
            $relay->execute($message);

            $relayed++;
        });

        return $relayed;
    }

    /**
     * Messages this command has already re-dispatched that are still sitting
     * unprocessed. Relaying them again would not help — nothing is consuming
     * the queue they were dispatched to, which is what a queue name the
     * workers do not run looks like from here.
     */
    private function warnAboutMessagesNothingConsumed(): void
    {
        $abandoned = InboxMessages::query()->relayed()->unprocessed()->count();

        if ($abandoned === 0) {
            return;
        }

        $queue = config('switchboard.queue.name') ?: 'the default queue';

        $warning = "{$abandoned} relayed inbox " . str('message')->plural($abandoned)
            . ' ' . ($abandoned === 1 ? 'is' : 'are') . ' still unprocessed. '
            . "Check that a worker is consuming {$queue}.";

        $this->components->warn($warning);

        Log::warning('Switchboard relayed inbox messages that are still unprocessed.', [
            'count' => $abandoned,
            'queue' => $queue,
        ]);
    }

    private function cutoff(): Carbon
    {
        $staleAfter = $this->option('stale-after');

        return is_numeric($staleAfter)
            ? Carbon::now()->subSeconds((int) $staleAfter)
            : Staleness::cutoff();
    }

    private function limit(): int
    {
        $limit = $this->option('limit');

        return is_numeric($limit) ? max(1, (int) $limit) : 1000;
    }
}
