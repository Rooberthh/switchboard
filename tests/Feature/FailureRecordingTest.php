<?php

declare(strict_types=1);

use Rooberthh\Switchboard\Models\InboxMessage;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Tests\Fixtures\FakeDriver;
use Rooberthh\Switchboard\Tests\Fixtures\ThrowingHandler;
use Illuminate\Testing\TestResponse;
use Rooberthh\Switchboard\Jobs\ProcessInboxMessage;

beforeEach(function () {
    ThrowingHandler::$attempts = 0;
    ThrowingHandler::$succeedFrom = [];

    // A real queue, so retries are real retries rather than an assertion
    // about configuration.
    config([
        'queue.default' => 'database',
        'switchboard.inbox.tries' => 3,
        'switchboard.inbox.backoff' => [0],
    ]);

    Switchboard::extend('acme', new FakeDriver());
    Switchboard::handledBy('acme', ThrowingHandler::class);
    Switchboard::route('acme');
});

function deliverOne(string $id = 'evt_1'): TestResponse
{
    return test()->postJson('webhooks/acme', ['id' => $id, 'type' => 'invoice.paid', 'data' => []]);
}

function work(): void
{
    test()->artisan('queue:work', ['--once' => true])->run();
}

function message(): InboxMessage
{
    return InboxMessage::query()->sole()->refresh();
}

it('answers the provider before the handler has a chance to fail', function () {
    deliverOne()->assertNoContent();

    expect(ThrowingHandler::$attempts)->toBe(0);
});

it('leaves a message unprocessed while retries remain', function () {
    deliverOne()->assertNoContent();

    work();

    expect(ThrowingHandler::$attempts)->toBe(1)
        ->and(message()->isUnprocessed())->toBeTrue()
        ->and(message()->failed_at)->toBeNull()
        ->and(message()->last_error)->toBeNull();
});

it('records the failure once retries are exhausted', function () {
    $this->freezeTime();

    deliverOne()->assertNoContent();

    work();
    work();
    work();

    expect(ThrowingHandler::$attempts)->toBe(3)
        ->and(message()->isFailed())->toBeTrue()
        ->and(message()->failed_at?->timestamp)->toBe(now()->timestamp)
        ->and(message()->processed_at)->toBeNull();
});

it('records an error an operator can diagnose without reproducing it', function () {
    deliverOne()->assertNoContent();

    work();
    work();
    work();

    expect(message()->last_error)
        ->toContain('RuntimeException')
        ->toContain('the ledger rejected evt_1')
        ->toContain('ThrowingHandler.php');
});

it('sets processed_at when a later attempt succeeds', function () {
    deliverOne()->assertNoContent();

    work();

    expect(message()->isUnprocessed())->toBeTrue();

    ThrowingHandler::$succeedFrom = ['evt_1'];

    work();

    expect(message()->isProcessed())->toBeTrue()
        ->and(message()->failed_at)->toBeNull()
        ->and(message()->last_error)->toBeNull();
});

it('never lets a handler failure reach the provider as a 5xx', function () {
    deliverOne()->assertNoContent();

    work();
    work();
    work();

    expect(message()->isFailed())->toBeTrue();

    // And a redelivery of the same event is still answered, not re-run.
    deliverOne()->assertNoContent();
});

it('backs off exponentially between attempts', function () {
    $shipped = (require __DIR__ . '/../../config/switchboard.php')['inbox']['backoff'];

    config(['switchboard.inbox.backoff' => $shipped]);

    $backoff = (new ProcessInboxMessage(1))->backoff();

    expect($backoff)->toBe($shipped)
        ->and($backoff)->not->toBeEmpty();

    foreach (array_slice($backoff, 1) as $index => $seconds) {
        expect($seconds)->toBeGreaterThanOrEqual($backoff[$index] * 2);
    }
});

it('distinguishes succeeded, failed and unprocessed messages by query', function () {
    $common = ['provider' => 'acme', 'event_type' => 'invoice.paid', 'data' => [], 'occurred_at' => now()];

    InboxMessage::query()->create([...$common, 'event_id' => 'done', 'processed_at' => now()]);
    InboxMessage::query()->create([...$common, 'event_id' => 'broken', 'failed_at' => now(), 'last_error' => 'boom']);
    InboxMessage::query()->create([...$common, 'event_id' => 'waiting']);

    expect(InboxMessage::query()->processed()->pluck('event_id')->all())->toBe(['done'])
        ->and(InboxMessage::query()->failed()->pluck('event_id')->all())->toBe(['broken'])
        ->and(InboxMessage::query()->unprocessed()->pluck('event_id')->all())->toBe(['waiting'])
        ->and(InboxMessage::query()->forProvider('acme')->count())->toBe(3);
});
