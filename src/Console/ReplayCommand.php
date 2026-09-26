<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Console;

use Illuminate\Console\Command;
use Rooberthh\Switchboard\Actions\ReplayInboxMessageAction;
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
                            {--provider= : Only replay messages from this provider}
                            {--event= : Only replay the message with this event ID}
                            {--id= : Only replay the message with this id}';

    protected $description = 'Re-dispatch failed inbox messages';

    public function handle(): int
    {
        $query = InboxMessages::query();

        /** @var array<string, string> $given */
        $given = [];

        foreach (['provider', 'event', 'id'] as $filter) {
            $value = $this->option($filter);

            if (! is_string($value)) {
                continue;
            }

            if (trim($value) === '') {
                // Not the same as leaving it off: a script whose variable went
                // missing must not replay every failure there is.
                $this->components->error("The --{$filter} option was given without a value.");

                return self::FAILURE;
            }

            // Refused rather than left to match nothing: Postgres throws when
            // an integer key is compared with a string that is not one.
            if ($filter === 'id' && (! ctype_digit($value) || (int) $value < 1)) {
                $this->components->error('The --id option must be an inbox message id, such as 42.');

                return self::FAILURE;
            }

            match ($filter) {
                'provider' => $query->forProvider($value),
                'event' => $query->where('event_id', $value),
                'id' => $query->whereKey($value),
            };

            $given[$filter] = $value;
        }

        // An event ID is unique per provider, not overall: one event is stored
        // once for each provider that received it, such as a Stripe event two
        // modules' endpoints both subscribe to. Naming the event never meant
        // all of them, so the caller chooses.
        if (isset($given['event'])) {
            $matches = (clone $query)->orderBy('id')->get();

            if ($matches->pluck('provider')->unique()->count() > 1) {
                $this->components->error("Event {$given['event']} is stored under more than one provider. Choose one with --provider or --id:");
                $this->components->bulletList($matches->map(
                    fn(InboxMessage $message): string => "#{$message->id} {$message->provider}, " . self::state($message),
                )->all());

                return self::FAILURE;
            }
        }

        $replayed = 0;
        $replay = app(ReplayInboxMessageAction::class);

        // Paged by id, not by offset: clearing failed_at removes the row from
        // this query, so offset paging would step over as many messages as it
        // replayed.
        (clone $query)->failed()->eachById(function (InboxMessage $message) use (&$replayed, $replay): void {
            $replay->execute($message);

            $replayed++;
        });

        // Asked for one message and replayed nothing: say why, and fail, so a
        // mistyped id is not reported as a replay that found nothing to do.
        if ($replayed === 0 && (isset($given['event']) || isset($given['id']))) {
            $this->components->error(self::whyNotReplayed($query->first()));

            return self::FAILURE;
        }

        $this->components->info("Re-dispatched {$replayed} failed inbox " . str('message')->plural($replayed) . '.');

        return self::SUCCESS;
    }

    private static function whyNotReplayed(?InboxMessage $message): string
    {
        if ($message === null) {
            return 'No inbox message matches.';
        }

        return "Inbox message #{$message->id} ({$message->event_id} from {$message->provider}) has not failed: it is "
            . self::state($message) . '. Only failed messages are replayed.';
    }

    private static function state(InboxMessage $message): string
    {
        return match (true) {
            $message->isFailed() => 'failed',
            $message->isProcessed() => 'processed',
            default => 'unprocessed',
        };
    }
}
