<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Rooberthh\Switchboard\Inbox\Staleness;
use Rooberthh\Switchboard\Models\InboxMessage;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Tests\Fixtures\AcmeHandler;
use Rooberthh\Switchboard\Tests\Fixtures\FakeDriver;

beforeEach(function () {
    AcmeHandler::$calls = [];

    Switchboard::extend('acme', new FakeDriver());
    Switchboard::extend('other', new FakeDriver());
    Switchboard::handledBy('acme', AcmeHandler::class);
    Switchboard::handledBy('other', AcmeHandler::class);
    Switchboard::route('acme');
});

function stranded(string $provider = 'acme', string $eventId = 'evt_1', array $lifecycle = []): InboxMessage
{
    $message = InboxMessage::query()->create([
        'provider' => $provider,
        'event_id' => $eventId,
        'event_type' => 'invoice.paid',
        'data' => ['amount' => 1000],
        'occurred_at' => now(),
        ...$lifecycle,
    ]);

    // Old enough that no worker could still legitimately be holding it.
    $message->forceFill(['created_at' => Staleness::cutoff()->subMinute()])->save();

    return $message->refresh();
}

it('re-dispatches a message left unprocessed for longer than it could be in flight', function () {
    Queue::fake();

    $message = stranded();

    $this->artisan('switchboard:relay')->expectsOutputToContain('1')->assertSuccessful();

    Queue::assertCount(1);

    expect($message->refresh()->relayed_at)->not->toBeNull();
});

it('leaves a message alone until it is older than the staleness window', function () {
    Queue::fake();

    InboxMessage::query()->create([
        'provider' => 'acme',
        'event_id' => 'evt_1',
        'event_type' => 'invoice.paid',
        'data' => [],
        'occurred_at' => now(),
    ]);

    $this->artisan('switchboard:relay')->assertSuccessful();

    Queue::assertCount(0);
});

it('leaves processed and failed messages alone', function () {
    Queue::fake();

    $processed = stranded('acme', 'evt_1', ['processed_at' => now()]);
    $failed = stranded('acme', 'evt_2', ['failed_at' => now(), 'last_error' => 'boom']);

    $this->artisan('switchboard:relay')->assertSuccessful();

    Queue::assertCount(0);

    expect($processed->refresh()->relayed_at)->toBeNull()
        ->and($failed->refresh()->relayed_at)->toBeNull();
});

it('relays a message once and only once, so an outage cannot amplify', function () {
    Queue::fake();

    stranded();

    $this->artisan('switchboard:relay')->assertSuccessful();
    $this->artisan('switchboard:relay')->assertSuccessful();
    $this->artisan('switchboard:relay')->assertSuccessful();

    Queue::assertCount(1);
});

it('leaves a relayed message unprocessed, because relaying is not a lifecycle step', function () {
    Queue::fake();

    $message = stranded();

    $this->artisan('switchboard:relay')->assertSuccessful();

    expect($message->refresh()->isUnprocessed())->toBeTrue()
        ->and(InboxMessage::query()->unprocessed()->count())->toBe(1)
        ->and(InboxMessage::query()->relayed()->count())->toBe(1);
});

it('leaves a message unrelayed when it cannot be queued', function () {
    config(['queue.default' => 'database']);

    // The outage that stranded the message has not finished yet.
    Schema::drop('jobs');

    $message = stranded();

    expect(fn() => $this->artisan('switchboard:relay')->run())->toThrow(Exception::class);

    expect($message->refresh()->relayed_at)->toBeNull();
});

it('filters by provider', function () {
    Queue::fake();

    $mine = stranded('acme', 'evt_1');
    $theirs = stranded('other', 'evt_2');

    $this->artisan('switchboard:relay', ['--provider' => 'acme'])->assertSuccessful();

    Queue::assertCount(1);

    expect($mine->refresh()->relayed_at)->not->toBeNull()
        ->and($theirs->refresh()->relayed_at)->toBeNull();
});

it('refuses a --provider given without a value', function () {
    Queue::fake();

    stranded();

    $this->artisan('switchboard:relay', ['--provider' => ''])->assertFailed();

    Queue::assertCount(0);
});

it('sweeps no more than the limit in one run', function () {
    Queue::fake();

    stranded('acme', 'evt_1');
    stranded('acme', 'evt_2');
    stranded('acme', 'evt_3');

    $this->artisan('switchboard:relay', ['--limit' => 2])->assertSuccessful();

    Queue::assertCount(2);

    expect(InboxMessage::query()->relayed()->count())->toBe(2);
});

it('takes a staleness window from the command line', function () {
    Queue::fake();

    InboxMessage::query()->create([
        'provider' => 'acme',
        'event_id' => 'evt_1',
        'event_type' => 'invoice.paid',
        'data' => [],
        'occurred_at' => now(),
        'created_at' => now()->subMinutes(10),
    ]);

    $this->artisan('switchboard:relay', ['--stale-after' => 60])->assertSuccessful();

    Queue::assertCount(1);
});

it('warns about messages it relayed that nothing has consumed since', function () {
    Queue::fake();
    Log::spy();

    $message = stranded();
    $message->forceFill(['relayed_at' => now()->subHour()])->save();

    $this->artisan('switchboard:relay')
        ->expectsOutputToContain('still unprocessed')
        ->assertSuccessful();

    Log::shouldHaveReceived('warning')->once();
});

it('says nothing alarming when there is nothing to relay', function () {
    Queue::fake();
    Log::spy();

    $this->artisan('switchboard:relay')->assertSuccessful();

    Log::shouldNotHaveReceived('warning');
});

it('recovers a message whose job never reached the queue', function () {
    // The whole point, end to end: the dispatch is swallowed, so nothing will
    // ever process this message until the relay notices it.
    $queue = app('queue');

    Queue::fake();

    $this->postJson('webhooks/acme', ['id' => 'evt_1', 'type' => 'invoice.paid', 'data' => ['amount' => 1000]])
        ->assertNoContent();

    expect(AcmeHandler::$calls)->toBe([]);

    // The queue comes back, still holding nothing for this message.
    Queue::swap($queue);

    config(['queue.default' => 'database']);

    $this->travel(Staleness::seconds() + 60)->seconds();

    $this->artisan('switchboard:relay')->assertSuccessful();
    $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->run();

    expect(InboxMessage::query()->sole()->refresh()->isProcessed())->toBeTrue()
        ->and(AcmeHandler::$calls)->toBe(['invoicePaid:evt_1']);
});
