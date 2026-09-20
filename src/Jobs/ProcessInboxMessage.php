<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Rooberthh\Switchboard\Events\InboxMessageFailed;
use Rooberthh\Switchboard\Events\InboxMessageProcessed;
use Rooberthh\Switchboard\Inbox\InboxMessages;
use Rooberthh\Switchboard\Switchboard;
use Throwable;

/**
 * Runs the application's handler against a message that is already stored.
 *
 * @internal
 */
final class ProcessInboxMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    public function __construct(public readonly int $inboxMessageId)
    {
        $this->tries = (int) config('switchboard.inbox.tries', 5);

        $this->onConnection(config('switchboard.queue.connection'));
        $this->onQueue(config('switchboard.queue.name'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        /** @var list<int> $backoff */
        $backoff = config('switchboard.inbox.backoff') ?: [10, 60, 360, 2160];

        return $backoff;
    }

    public function handle(): void
    {
        $message = InboxMessages::find($this->inboxMessageId);

        if ($message === null || ! $message->isUnprocessed()) {
            return;
        }

        Switchboard::handler($message->provider)->handle($message);

        $message->forceFill(['processed_at' => now()])->save();

        event(new InboxMessageProcessed($message));
    }

    /**
     * Called once the attempts are spent. Until then the message stays
     * unprocessed: there is no in-flight state and no attempt counter.
     * @param Throwable $exception
     */
    public function failed(Throwable $exception): void
    {
        $message = InboxMessages::find($this->inboxMessageId);

        if ($message === null || $message->isProcessed()) {
            return;
        }

        $message->forceFill([
            'failed_at' => now(),
            'last_error' => self::describe($exception),
        ])->save();

        event(new InboxMessageFailed($message, $exception));
    }

    /**
     * Enough to diagnose the failure without reproducing it, and bounded, so
     * one pathological exception cannot fill the column.
     * @param Throwable $exception
     */
    private static function describe(Throwable $exception): string
    {
        return Str::limit(sprintf(
            '%s: %s in %s:%d',
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
        ), 2000);
    }
}
