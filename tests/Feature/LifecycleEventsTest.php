<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Rooberthh\Switchboard\Events\InboxMessageFailed;
use Rooberthh\Switchboard\Events\InboxMessageProcessed;
use Rooberthh\Switchboard\Events\InboxMessageReceived;
use Rooberthh\Switchboard\Models\InboxMessage;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Tests\Fixtures\FakeProvider;
use Rooberthh\Switchboard\Tests\Fixtures\RecordingHandler;
use Rooberthh\Switchboard\Tests\Fixtures\ThrowingHandler;
use Rooberthh\Switchboard\Tests\Fixtures\ThrowingProvider;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    RecordingHandler::$calls = [];
    ThrowingHandler::$attempts = 0;
    ThrowingHandler::$succeedFrom = [];

    config([
        'queue.default' => 'database',
        'switchboard.inbox.tries' => 2,
        'switchboard.inbox.backoff' => [0],
    ]);

    Switchboard::provider(FakeProvider::class);
});

function arrive(string $id = 'evt_1'): TestResponse
{
    return test()->postJson('webhooks/acme', ['id' => $id, 'type' => 'invoice.paid', 'data' => ['amount' => 1000]]);
}

function drain(): void
{
    test()->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->run();
}

it('fires an event once a message is persisted', function () {
    Event::fake([InboxMessageReceived::class]);

    arrive()->assertNoContent();

    Event::assertDispatched(InboxMessageReceived::class, function (InboxMessageReceived $event) {
        return $event->message->event_id === 'evt_1'
            && $event->message->exists;
    });
});

it('does not fire for a duplicate delivery', function () {
    Event::fake([InboxMessageReceived::class]);

    arrive()->assertNoContent();
    arrive()->assertNoContent();

    Event::assertDispatchedTimes(InboxMessageReceived::class, 1);
});

it('holds the received event until the surrounding transaction commits', function () {
    $seen = [];

    Event::listen(InboxMessageReceived::class, function (InboxMessageReceived $event) use (&$seen) {
        $seen[] = $event;
    });

    DB::beginTransaction();

    arrive()->assertNoContent();

    expect($seen)->toBeEmpty();

    DB::commit();

    expect($seen)->toHaveCount(1);
});

it('never fires the received event for a message that was rolled back', function () {
    $seen = [];

    Event::listen(InboxMessageReceived::class, function (InboxMessageReceived $event) use (&$seen) {
        $seen[] = $event;
    });

    DB::beginTransaction();

    arrive()->assertNoContent();

    DB::rollBack();

    expect($seen)->toBeEmpty()
        ->and(InboxMessage::query()->count())->toBe(0);
});

it('fires an event when a handler processes a message', function () {
    Event::fake([InboxMessageProcessed::class]);

    arrive()->assertNoContent();

    drain();

    Event::assertDispatched(InboxMessageProcessed::class, function (InboxMessageProcessed $event) {
        return $event->message->event_id === 'evt_1'
            && $event->message->isProcessed();
    });
});

it('fires an event when a message has failed for good', function () {
    Switchboard::flush();
    Switchboard::provider(ThrowingProvider::class);

    Event::fake([InboxMessageFailed::class]);

    arrive()->assertNoContent();

    drain();

    Event::assertDispatchedTimes(InboxMessageFailed::class, 1);

    Event::assertDispatched(InboxMessageFailed::class, function (InboxMessageFailed $event) {
        return $event->message->isFailed()
            && $event->message->last_error !== null
            && $event->exception->getMessage() === 'the ledger rejected evt_1';
    });
});

it('does not fire the failed event while retries remain', function () {
    Switchboard::flush();
    Switchboard::provider(ThrowingProvider::class);

    config(['switchboard.inbox.tries' => 5]);

    Event::fake([InboxMessageFailed::class]);

    arrive()->assertNoContent();

    test()->artisan('queue:work', ['--once' => true])->run();

    Event::assertNotDispatched(InboxMessageFailed::class);
});

it('leaves an application that listens to none of them unaffected', function () {
    arrive()->assertNoContent();

    drain();

    expect(InboxMessage::query()->sole()->isProcessed())->toBeTrue()
        ->and(RecordingHandler::$calls)->toBe(['invoice.paid:evt_1']);
});
