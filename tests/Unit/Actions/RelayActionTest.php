<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Rooberthh\Switchboard\Actions\Inbox\RelayAction;
use Rooberthh\Switchboard\Exceptions\IllegalTransition;
use Rooberthh\Switchboard\Jobs\ProcessInboxMessage;
use Rooberthh\Switchboard\Models\InboxMessage;

it('marks the message relayed and queues it', function () {
    Queue::fake();
    $this->freezeTime();

    $message = inboxMessage();

    app(RelayAction::class)->execute($message);

    Queue::assertPushed(
        ProcessInboxMessage::class,
        fn(ProcessInboxMessage $job): bool => $job->inboxMessageId === $message->id,
    );

    expect($message->refresh()->relayed_at->timestamp)->toBe(now()->timestamp);
});

it('marks before it dispatches, so a run that dies partway cannot relay twice', function () {
    // A real connection, because the queued event does not fire on a fake.
    config(['queue.default' => 'database']);

    $message = inboxMessage();
    $markedWhenQueued = null;

    Event::listen(JobQueued::class, function () use ($message, &$markedWhenQueued): void {
        // Read the row back, not the in-memory model: the mark has to be
        // durable by the time anything can pick the job up.
        $markedWhenQueued = InboxMessage::query()->find($message->id)?->relayed_at;
    });

    app(RelayAction::class)->execute($message);

    expect($markedWhenQueued)->not->toBeNull();
});

it('puts the mark back when the queue will not take the job', function () {
    config(['queue.default' => 'database']);

    // The outage that stranded the message has not finished yet.
    Schema::drop('jobs');

    $message = inboxMessage();

    expect(fn() => app(RelayAction::class)->execute($message))->toThrow(Exception::class);

    expect($message->refresh()->relayed_at)->toBeNull();
});

it('refuses to relay a message a sweep has already relayed', function () {
    Queue::fake();

    $message = inboxMessage(['relayed_at' => now()->subHour()]);

    expect(fn() => app(RelayAction::class)->execute($message))->toThrow(IllegalTransition::class);

    Queue::assertNothingPushed();
});

it('leaves the message unprocessed, because relaying is not a lifecycle step', function () {
    Queue::fake();

    $message = inboxMessage();

    app(RelayAction::class)->execute($message);

    expect($message->refresh()->isUnprocessed())->toBeTrue();
});
