<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Rooberthh\Switchboard\Actions\Inbox\FailAction;
use Rooberthh\Switchboard\Actions\Inbox\ProcessAction;
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

        app(ProcessAction::class)->execute($message);
    }

    /**
     * Called once the attempts are spent. Until then the message stays
     * unprocessed: there is no in-flight state and no attempt counter.
     * @param Throwable $exception
     */
    public function failed(Throwable $exception): void
    {
        $message = InboxMessages::find($this->inboxMessageId);

        // The processed check stays here rather than inside the act: a late
        // failure callback for a message another worker already finished is a
        // race the queue genuinely produces, not a violation.
        if ($message === null || $message->isProcessed()) {
            return;
        }

        app(FailAction::class)->execute($message, $exception);
    }
}
