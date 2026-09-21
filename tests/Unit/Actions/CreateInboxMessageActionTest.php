<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Rooberthh\Switchboard\Actions\CreateInboxMessageAction;
use Rooberthh\Switchboard\Events\InboxMessageReceived;
use Rooberthh\Switchboard\Inbox\InboxMessageData;
use Rooberthh\Switchboard\Jobs\ProcessInboxMessage;
use Rooberthh\Switchboard\Models\InboxMessage;

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

it('persists the message unprocessed, with every field the driver supplied', function () {
    Queue::fake();
    $message = app(CreateInboxMessageAction::class)->execute(inboxMessageData([
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

    $message = app(CreateInboxMessageAction::class)->execute(inboxMessageData());

    expect($message->occurred_at->timestamp)->toBe(now()->timestamp);
});

it('queues and announces the message as part of the act', function () {
    Queue::fake();
    Event::fake([InboxMessageReceived::class]);

    $message = app(CreateInboxMessageAction::class)->execute(inboxMessageData());

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

    $first = app(CreateInboxMessageAction::class)->execute(inboxMessageData());
    $second = app(CreateInboxMessageAction::class)->execute(inboxMessageData([
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
    app(CreateInboxMessageAction::class)->execute(inboxMessageData());
    app(CreateInboxMessageAction::class)->execute(inboxMessageData(['provider' => 'stripe']));

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

    app(CreateInboxMessageAction::class)->execute(inboxMessageData());

    DB::rollBack();

    expect($seen)->toBeEmpty()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(InboxMessage::query()->count())->toBe(0);
});
