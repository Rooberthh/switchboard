<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Rooberthh\Switchboard\Models\InboxMessage;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Tests\Fixtures\FakeDriver;
use Rooberthh\Switchboard\Tests\Fixtures\ThrowingHandler;

beforeEach(function () {
    ThrowingHandler::$attempts = 0;
    ThrowingHandler::$succeedFrom = [];

    Switchboard::extend('acme', new FakeDriver());
    Switchboard::extend('other', new FakeDriver());
    Switchboard::handledBy('acme', ThrowingHandler::class);
    Switchboard::handledBy('other', ThrowingHandler::class);
});

function store(string $provider, string $eventId, array $lifecycle = []): InboxMessage
{
    return InboxMessage::query()->create([
        'provider' => $provider,
        'event_id' => $eventId,
        'event_type' => 'invoice.paid',
        'data' => ['amount' => 1000],
        'occurred_at' => now(),
        ...$lifecycle,
    ]);
}

function failedMessage(string $provider, string $eventId): InboxMessage
{
    return store($provider, $eventId, ['failed_at' => now(), 'last_error' => 'an old error']);
}

it('re-dispatches failed messages', function () {
    Queue::fake();

    failedMessage('acme', 'evt_1');
    failedMessage('acme', 'evt_2');

    $this->artisan('switchboard:replay')->assertSuccessful();

    Queue::assertCount(2);
});

it('returns a replayed message to unprocessed', function () {
    Queue::fake();

    $message = failedMessage('acme', 'evt_1');

    $this->artisan('switchboard:replay')->assertSuccessful();

    expect($message->refresh()->isUnprocessed())->toBeTrue()
        ->and($message->failed_at)->toBeNull()
        ->and($message->last_error)->toBeNull();
});

it('leaves succeeded and unprocessed messages alone', function () {
    Queue::fake();

    $succeeded = store('acme', 'evt_1', ['processed_at' => now()]);
    $unprocessed = store('acme', 'evt_2');
    failedMessage('acme', 'evt_3');

    $this->artisan('switchboard:replay')->assertSuccessful();

    Queue::assertCount(1);

    expect($succeeded->refresh()->isProcessed())->toBeTrue()
        ->and($unprocessed->refresh()->isUnprocessed())->toBeTrue();
});

it('filters by provider', function () {
    Queue::fake();

    $mine = failedMessage('acme', 'evt_1');
    $theirs = failedMessage('other', 'evt_2');

    $this->artisan('switchboard:replay', ['--provider' => 'acme'])->assertSuccessful();

    Queue::assertCount(1);

    expect($mine->refresh()->isUnprocessed())->toBeTrue()
        ->and($theirs->refresh()->isFailed())->toBeTrue();
});

it('reports how many messages it re-dispatched', function () {
    Queue::fake();

    failedMessage('acme', 'evt_1');
    failedMessage('acme', 'evt_2');

    $this->artisan('switchboard:replay')
        ->expectsOutputToContain('2')
        ->assertSuccessful();
});

it('finds nothing the second time', function () {
    Queue::fake();

    failedMessage('acme', 'evt_1');

    $this->artisan('switchboard:replay')->assertSuccessful();
    $this->artisan('switchboard:replay')->expectsOutputToContain('0')->assertSuccessful();

    Queue::assertCount(1);
});

it('processes a replayed message that succeeds this time', function () {
    config(['queue.default' => 'database', 'switchboard.inbox.tries' => 1]);

    failedMessage('acme', 'evt_1');

    ThrowingHandler::$succeedFrom = ['evt_1'];

    $this->artisan('switchboard:replay')->assertSuccessful();
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->run();

    expect(InboxMessage::query()->sole()->refresh()->isProcessed())->toBeTrue();
});

it('records the new error when a replayed message fails again', function () {
    config(['queue.default' => 'database', 'switchboard.inbox.tries' => 1]);

    failedMessage('acme', 'evt_1');

    $this->artisan('switchboard:replay')->assertSuccessful();
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->run();

    $message = InboxMessage::query()->sole()->refresh();

    expect($message->isFailed())->toBeTrue()
        ->and($message->last_error)->not->toBe('an old error')
        ->and($message->last_error)->toContain('the ledger rejected evt_1');
});
