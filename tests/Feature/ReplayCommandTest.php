<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Rooberthh\Switchboard\Models\InboxMessage;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Tests\Fixtures\ThrowingHandler;
use Rooberthh\Switchboard\Tests\Fixtures\ThrowingProvider;

beforeEach(function () {
    ThrowingHandler::$attempts = 0;
    ThrowingHandler::$succeedFrom = [];

    Switchboard::provider(ThrowingProvider::class);
    Switchboard::provider((new class extends ThrowingProvider {
        public static function name(): string
        {
            return 'other';
        }
    })::class);
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

it('replays every failed message, past the first chunk', function () {
    Queue::fake();

    $rows = [];

    for ($i = 1; $i <= 1001; $i++) {
        $rows[] = [
            'provider' => 'acme',
            'event_id' => "evt_{$i}",
            'event_type' => 'invoice.paid',
            'data' => '{}',
            'occurred_at' => now(),
            'failed_at' => now(),
            'last_error' => 'an old error',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    DB::table('switchboard_inbox_messages')->insert($rows);

    $this->artisan('switchboard:replay')->expectsOutputToContain('1001')->assertSuccessful();

    Queue::assertCount(1001);

    expect(InboxMessage::query()->failed()->count())->toBe(0);
});

it('leaves a message failed when it cannot be queued', function () {
    config(['queue.default' => 'database']);

    // The outage that produced the backlog has not finished yet.
    Schema::drop('jobs');

    $message = failedMessage('acme', 'evt_1');

    expect(fn() => $this->artisan('switchboard:replay')->run())->toThrow(Exception::class);

    expect($message->refresh()->isFailed())->toBeTrue()
        ->and($message->last_error)->toBe('an old error');
});

it('replays only the failed message with the given event ID', function () {
    Queue::fake();

    $target = failedMessage('acme', 'evt_1');
    $other = failedMessage('acme', 'evt_2');

    $this->artisan('switchboard:replay', ['--event' => 'evt_1'])->assertSuccessful();

    Queue::assertCount(1);

    expect($target->refresh()->isUnprocessed())->toBeTrue()
        ->and($other->refresh()->isFailed())->toBeTrue();
});

it('replays only the failed message with the given id', function () {
    Queue::fake();

    $other = failedMessage('acme', 'evt_1');
    $target = failedMessage('acme', 'evt_2');

    $this->artisan('switchboard:replay', ['--id' => (string) $target->id])->assertSuccessful();

    Queue::assertCount(1);

    expect($target->refresh()->isUnprocessed())->toBeTrue()
        ->and($other->refresh()->isFailed())->toBeTrue();
});

it('refuses to replay a targeted message that has not failed, saying why', function () {
    Queue::fake();

    $succeeded = store('acme', 'evt_1', ['processed_at' => now()]);

    expect(Artisan::call('switchboard:replay', ['--event' => 'evt_1']))->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain("#{$succeeded->id}")->toContain('processed');

    Queue::assertCount(0);

    expect($succeeded->refresh()->isProcessed())->toBeTrue();
});

it('refuses to replay a targeted message that does not exist', function () {
    Queue::fake();

    failedMessage('acme', 'evt_1');

    $this->artisan('switchboard:replay', ['--event' => 'evt_404'])
        ->expectsOutputToContain('No inbox message')
        ->assertFailed();

    Queue::assertCount(0);
});

it('refuses an event ID stored under two providers, listing the id of each', function () {
    Queue::fake();

    // One event from one sender, stored once per provider that received it.
    $mine = failedMessage('acme', 'evt_1');
    $theirs = store('other', 'evt_1', ['processed_at' => now()]);

    expect(Artisan::call('switchboard:replay', ['--event' => 'evt_1']))->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain("#{$mine->id} acme, failed")->toContain("#{$theirs->id} other, processed");

    Queue::assertCount(0);

    expect($mine->refresh()->isFailed())->toBeTrue();
});

it('replays an event ID stored under two providers once narrowed to one', function (string $narrowedBy) {
    Queue::fake();

    $mine = failedMessage('acme', 'evt_1');
    $theirs = failedMessage('other', 'evt_1');

    $narrowing = $narrowedBy === 'provider' ? ['--provider' => 'acme'] : ['--id' => (string) $mine->id];

    $this->artisan('switchboard:replay', ['--event' => 'evt_1', ...$narrowing])->assertSuccessful();

    Queue::assertCount(1);

    expect($mine->refresh()->isUnprocessed())->toBeTrue()
        ->and($theirs->refresh()->isFailed())->toBeTrue();
})->with(['provider', 'id']);

it('refuses an --event given without a value', function () {
    Queue::fake();

    failedMessage('acme', 'evt_1');

    $this->artisan('switchboard:replay', ['--event' => ''])->assertFailed();

    Queue::assertCount(0);
});

it('refuses an --id that is not a message id', function (string $id) {
    Queue::fake();

    failedMessage('acme', 'evt_1');

    $this->artisan('switchboard:replay', ['--id' => $id])
        ->expectsOutputToContain('--id')
        ->assertFailed();

    Queue::assertCount(0);
})->with(['abc', '-1', '1.5', '0']);

it('refuses a --provider given without a value', function () {
    Queue::fake();

    failedMessage('acme', 'evt_1');

    $this->artisan('switchboard:replay', ['--provider' => ''])->assertFailed();

    Queue::assertCount(0);

    expect(InboxMessage::query()->failed()->count())->toBe(1);
});
