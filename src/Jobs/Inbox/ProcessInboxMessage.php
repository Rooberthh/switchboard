<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Jobs\Inbox;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Rooberthh\Switchboard\Support\Inbox\InboxMessageLifecycle;
use Rooberthh\Switchboard\Support\Inbox\InboxMessages;
use Rooberthh\Switchboard\Support\Inbox\ProcessingPolicy;
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
        $this->tries = ProcessingPolicy::tries();

        $this->onConnection(config('switchboard.queue.connection'));
        $this->onQueue(config('switchboard.queue.name'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return ProcessingPolicy::backoff();
    }

    public function handle(): void
    {
        $message = InboxMessages::find($this->inboxMessageId);

        if ($message === null || ! $message->isUnprocessed()) {
            return;
        }

        $provider = Switchboard::resolve($message->provider);

        $handler = $provider->handlers[$message->event_type] ?? null;

        if ($handler === null) {
            $provider->unhandled($message);
        } else {
            app($handler)($message);
        }

        app(InboxMessageLifecycle::class)->processed($message);
    }

    /**
     * Called once the attempts are spent. Until then the message stays
     * unprocessed: there is no in-flight state and no attempt counter.
     * @param Throwable $exception
     */
    public function failed(Throwable $exception): void
    {
        $message = InboxMessages::find($this->inboxMessageId);

        if ($message === null) {
            return;
        }

        app(InboxMessageLifecycle::class)->failed($message, $exception);
    }
}
