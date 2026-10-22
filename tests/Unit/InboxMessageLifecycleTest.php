<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Rooberthh\Switchboard\DataObjects\Inbox\InboxMessageData;
use Rooberthh\Switchboard\Events\InboxMessageFailed;
use Rooberthh\Switchboard\Events\InboxMessageProcessed;
use Rooberthh\Switchboard\Events\InboxMessageReceived;
use Rooberthh\Switchboard\Jobs\Inbox\ProcessInboxMessage;
use Rooberthh\Switchboard\Models\InboxMessage;
use Rooberthh\Switchboard\Support\Inbox\InboxMessageLifecycle;

function lifecycle(): InboxMessageLifecycle
{
    return app(InboxMessageLifecycle::class);
}

function inboxMessageData(array $overrides = []): InboxMessageData
{
    return new InboxMessageData(...[
        'provider' => 'acme',
        'eventId' => 'evt_1',
        'eventType' => 'invoice.paid',
        'data' => ['amount' => 1000],
        ...$overrides,
    ]);
}

// ingest

it('persists the message unprocessed, with every field the driver supplied', function () {
    Queue::fake();
    $message = lifecycle()->ingest(inboxMessageData([
        'subject' => 'cus_12345',
        'occurredAt' => new DateTimeImmutable('2026-09-18T10:00:00+00:00'),
    ]));

    expect($message->is(InboxMessage::query()->sole()))->toBeTrue()
        ->and($message->provider)->toBe('acme')
        ->and($message->event_id)->toBe('evt_1')
        ->and($message->event_type)->toBe('invoice.paid')
        ->and($message->subject)->toBe('cus_12345')
        ->and($message->data)->toBe(['amount' => 1000])
        ->and($message->occurred_at->toIso8601String())->toBe('2026-09-18T10:00:00+00:00')
        ->and($message->isUnprocessed())->toBeTrue();
});

it('falls back to receipt time when the driver supplies no timestamp', function () {
    Queue::fake();
    $this->freezeTime();

    $message = lifecycle()->ingest(inboxMessageData());

    expect($message->occurred_at->timestamp)->toBe(now()->timestamp);
});

it('queues and announces an ingested message', function () {
    Queue::fake();
    Event::fake([InboxMessageReceived::class]);

    $message = lifecycle()->ingest(inboxMessageData());

    Queue::assertPushed(
        ProcessInboxMessage::class,
        fn(ProcessInboxMessage $job): bool => $job->inboxMessageId === $message->id,
    );
    Event::assertDispatched(
        InboxMessageReceived::class,
        fn(InboxMessageReceived $event): bool => $event->message->is($message),
    );
});

it('returns the stored message for a repeat, and queues and announces nothing more', function () {
    Queue::fake();
    Event::fake([InboxMessageReceived::class]);

    $first = lifecycle()->ingest(inboxMessageData());
    $second = lifecycle()->ingest(inboxMessageData([
        'eventType' => 'invoice.voided',
        'data' => ['amount' => 1],
    ]));

    expect($second->is($first))->toBeTrue()
        ->and($second->event_type)->toBe('invoice.paid')
        ->and($second->data)->toBe(['amount' => 1000])
        ->and(InboxMessage::query()->count())->toBe(1);

    Queue::assertPushed(ProcessInboxMessage::class, 1);
    Event::assertDispatchedTimes(InboxMessageReceived::class, 1);
});

it('keeps the same event ID from different providers apart', function () {
    Queue::fake();
    lifecycle()->ingest(inboxMessageData());
    lifecycle()->ingest(inboxMessageData(['provider' => 'stripe']));

    expect(InboxMessage::query()->count())->toBe(2);

    Queue::assertPushed(ProcessInboxMessage::class, 2);
});

it('queues and announces nothing when the surrounding transaction rolls back', function () {
    // Real listeners and a real queue: both fakes ignore the after-commit hold.
    config(['queue.default' => 'database']);

    $seen = [];

    Event::listen(InboxMessageReceived::class, function (InboxMessageReceived $event) use (&$seen): void {
        $seen[] = $event;
    });

    DB::beginTransaction();

    lifecycle()->ingest(inboxMessageData());

    DB::rollBack();

    expect($seen)->toBeEmpty()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(InboxMessage::query()->count())->toBe(0);
});

// processed

it('records that a handler succeeded', function () {
    $this->freezeTime();

    $message = inboxMessage();

    expect(lifecycle()->processed($message))->toBeTrue()
        ->and($message->isProcessed())->toBeTrue()
        ->and($message->refresh()->isProcessed())->toBeTrue()
        ->and($message->processed_at->timestamp)->toBe(now()->timestamp)
        ->and($message->isUnprocessed())->toBeFalse();
});

it('announces a message marked processed', function () {
    Event::fake([InboxMessageProcessed::class]);

    $message = inboxMessage();

    lifecycle()->processed($message);

    Event::assertDispatched(
        InboxMessageProcessed::class,
        fn(InboxMessageProcessed $event): bool => $event->message->is($message) && $event->message->isProcessed(),
    );
});

it('marks a message processed once, and announces it once', function () {
    Event::fake([InboxMessageProcessed::class]);

    $message = inboxMessage();

    expect(lifecycle()->processed($message))->toBeTrue()
        ->and(lifecycle()->processed(InboxMessage::query()->sole()))->toBeFalse()
        ->and($message->refresh()->isProcessed())->toBeTrue();

    Event::assertDispatchedTimes(InboxMessageProcessed::class, 1);
});

it('leaves a failed message failed when it is marked processed', function () {
    Event::fake([InboxMessageProcessed::class]);

    $message = inboxMessage(['failed_at' => now(), 'last_error' => 'boom']);

    expect(lifecycle()->processed($message))->toBeFalse()
        ->and($message->isProcessed())->toBeFalse();

    expect($message->refresh()->isFailed())->toBeTrue()
        ->and($message->isProcessed())->toBeFalse()
        ->and($message->last_error)->toBe('boom');

    Event::assertNotDispatched(InboxMessageProcessed::class);
});

it('claims from the stored state, not the copy in memory', function () {
    $message = inboxMessage();

    // Another worker fails it after this one loaded it.
    InboxMessage::query()->whereKey($message->id)->update(['failed_at' => now(), 'last_error' => 'boom']);

    expect(lifecycle()->processed($message))->toBeFalse()
        ->and($message->refresh()->isFailed())->toBeTrue()
        ->and($message->isProcessed())->toBeFalse();
});

// failed

it('records the failure and its diagnosis', function () {
    $this->freezeTime();

    $message = inboxMessage();

    expect(lifecycle()->failed($message, new RuntimeException('the ledger rejected evt_1')))->toBeTrue()
        ->and($message->isFailed())->toBeTrue();

    expect($message->refresh()->isFailed())->toBeTrue()
        ->and($message->failed_at->timestamp)->toBe(now()->timestamp)
        ->and($message->last_error)->toContain('RuntimeException')
        ->and($message->last_error)->toContain('the ledger rejected evt_1');
});

it('bounds last_error, so one pathological exception cannot fill the column', function () {
    $message = inboxMessage();

    lifecycle()->failed($message, new RuntimeException(str_repeat('a', 5000)));

    $lastError = (string) $message->refresh()->last_error;

    // 2000 characters plus the ellipsis Str::limit appends.
    expect(strlen($lastError))->toBeLessThanOrEqual(2003)
        ->and($lastError)->toEndWith('...');
});

it('announces the failure, carrying the exception', function () {
    Event::fake([InboxMessageFailed::class]);

    $message = inboxMessage();
    $exception = new RuntimeException('boom');

    lifecycle()->failed($message, $exception);

    Event::assertDispatched(
        InboxMessageFailed::class,
        fn(InboxMessageFailed $event): bool => $event->message->is($message)
            && $event->exception === $exception
            && str_contains((string) $event->message->last_error, 'boom'),
    );
});

it('leaves a processed message processed when it is failed', function () {
    Event::fake([InboxMessageFailed::class]);

    $message = inboxMessage(['processed_at' => now()]);

    expect(lifecycle()->failed($message, new RuntimeException('boom')))->toBeFalse();

    expect($message->refresh()->isProcessed())->toBeTrue()
        ->and($message->isFailed())->toBeFalse()
        ->and($message->last_error)->toBeNull();

    Event::assertNotDispatched(InboxMessageFailed::class);
});

it('keeps the first diagnosis when a message fails twice, and announces it once', function () {
    Event::fake([InboxMessageFailed::class]);

    $message = inboxMessage();

    expect(lifecycle()->failed($message, new RuntimeException('the first error')))->toBeTrue()
        ->and(lifecycle()->failed(InboxMessage::query()->sole(), new RuntimeException('the second error')))->toBeFalse();

    expect($message->refresh()->last_error)->toContain('the first error')
        ->and($message->last_error)->not->toContain('the second error');

    Event::assertDispatchedTimes(InboxMessageFailed::class, 1);
});

// relay

it('marks the message relayed and queues it', function () {
    Queue::fake();
    $this->freezeTime();

    $message = inboxMessage();

    expect(lifecycle()->relay($message))->toBeTrue()
        ->and($message->relayed_at->timestamp)->toBe(now()->timestamp);

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

    lifecycle()->relay($message);

    expect($markedWhenQueued)->not->toBeNull();
});

it('puts the mark back when the queue will not take the job', function () {
    config(['queue.default' => 'database']);

    // The outage that stranded the message has not finished yet.
    Schema::drop('jobs');

    $message = inboxMessage();

    expect(fn() => lifecycle()->relay($message))->toThrow(Exception::class);

    expect($message->refresh()->relayed_at)->toBeNull();
});

it('relays nothing for a message a sweep has already relayed', function () {
    Queue::fake();

    $relayedAt = now()->subHour();
    $message = inboxMessage(['relayed_at' => $relayedAt]);

    expect(lifecycle()->relay($message))->toBeFalse()
        ->and($message->refresh()->relayed_at->timestamp)->toBe($relayedAt->timestamp);

    Queue::assertNothingPushed();
});

it('relays a message once when two sweeps both read it as unrelayed', function () {
    Queue::fake();

    $message = inboxMessage();
    $sameRowReadByAnotherSweep = InboxMessage::query()->sole();

    expect(lifecycle()->relay($message))->toBeTrue()
        ->and(lifecycle()->relay($sameRowReadByAnotherSweep))->toBeFalse();

    Queue::assertPushed(ProcessInboxMessage::class, 1);
});

it('leaves the message unprocessed, because relaying is not a lifecycle step', function () {
    Queue::fake();

    $message = inboxMessage();

    lifecycle()->relay($message);

    expect($message->refresh()->isUnprocessed())->toBeTrue();
});

// replay

it('returns a failed message to unprocessed and queues it', function () {
    Queue::fake();

    $message = inboxMessage(['failed_at' => now(), 'last_error' => 'an old error']);

    expect(lifecycle()->replay($message))->toBeTrue()
        ->and($message->isUnprocessed())->toBeTrue();

    Queue::assertPushed(
        ProcessInboxMessage::class,
        fn(ProcessInboxMessage $job): bool => $job->inboxMessageId === $message->id,
    );

    expect($message->refresh()->isUnprocessed())->toBeTrue()
        ->and($message->failed_at)->toBeNull()
        ->and($message->last_error)->toBeNull();
});

it('puts the failure back, diagnosis and all, when the queue will not take the job', function () {
    config(['queue.default' => 'database']);

    // The outage that produced the backlog has not finished yet.
    Schema::drop('jobs');

    $failedAt = now()->subHour();
    $message = inboxMessage(['failed_at' => $failedAt, 'last_error' => 'an old error']);

    expect(fn() => lifecycle()->replay($message))->toThrow(Exception::class);

    $message->refresh();

    expect($message->isFailed())->toBeTrue()
        ->and($message->failed_at->timestamp)->toBe($failedAt->timestamp)
        ->and($message->last_error)->toBe('an old error');
});

it('replays nothing for a message that succeeded', function () {
    Queue::fake();

    $message = inboxMessage(['processed_at' => now()]);

    expect(lifecycle()->replay($message))->toBeFalse();

    Queue::assertNothingPushed();

    expect($message->refresh()->isProcessed())->toBeTrue();
});

it('replays nothing for a message that is merely unprocessed', function () {
    Queue::fake();

    $message = inboxMessage();

    expect(lifecycle()->replay($message))->toBeFalse();

    Queue::assertNothingPushed();

    expect($message->refresh()->isUnprocessed())->toBeTrue();
});
