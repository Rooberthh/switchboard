<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Rooberthh\Switchboard\Inbox\InboxMessages;
use Rooberthh\Switchboard\Switchboard;

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
        $backoff = config('switchboard.inbox.backoff', [10, 60, 300, 900]);

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
    }
}
