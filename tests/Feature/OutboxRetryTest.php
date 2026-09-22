<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Rooberthh\Switchboard\Events\OutboxDeliveryFailed;
use Rooberthh\Switchboard\Jobs\AttemptDelivery;
use Rooberthh\Switchboard\Models\Delivery;
use Rooberthh\Switchboard\Models\Endpoint;
use Rooberthh\Switchboard\Support\RetrySchedule;
use Rooberthh\Switchboard\Switchboard;

beforeEach(function () {
    $this->freezeTime();

    Http::preventStrayRequests();

    // No jitter, so waits are exact.
    app()->instance(RetrySchedule::class, new RetrySchedule(random: fn(): float => 0.0));

    Endpoint::query()->create(['url' => 'https://hooks.acme.test/hook', 'event_types' => ['invoice.paid']]);
});

function relayNow(): void
{
    test()->artisan('switchboard:outbox:relay')->assertSuccessful();
}

function onlyDelivery(): Delivery
{
    return Delivery::query()->sole();
}

it('schedules a failed attempt for the next wait and records why', function () {
    Http::fake(['*' => Http::response('down for maintenance', 500)]);

    Switchboard::emit('invoice.paid');
    relayNow();

    $delivery = onlyDelivery();

    expect($delivery->isPending())->toBeTrue()
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_status)->toBe(500)
        ->and($delivery->last_error)->toContain('500')
        ->and($delivery->next_attempt_at->getTimestamp())->toBe(now()->addSeconds(5)->getTimestamp());
});

it('attempts again once the wait has passed, and not before', function () {
    Http::fakeSequence()->push(null, 500)->push(null, 204);

    Switchboard::emit('invoice.paid');
    relayNow();

    $this->travel(4)->seconds();
    relayNow();
    Http::assertSentCount(1);

    $this->travel(1)->seconds();
    relayNow();
    Http::assertSentCount(2);

    expect(onlyDelivery()->isDelivered())->toBeTrue()
        ->and(onlyDelivery()->attempts)->toBe(2);
});

it('retries a timeout or a refused connection on the schedule', function () {
    Http::fake(fn() => throw new ConnectionException('cURL error 28: Operation timed out'));

    Switchboard::emit('invoice.paid');
    relayNow();

    expect(onlyDelivery()->isPending())->toBeTrue()
        ->and(onlyDelivery()->last_error)->toContain('timed out')
        ->and(onlyDelivery()->last_status)->toBeNull();
});

it('honours retry-after on 429, 502 and 504', function (int $status) {
    Http::fake(['*' => Http::response(null, $status, ['Retry-After' => '120'])]);

    Switchboard::emit('invoice.paid');
    relayNow();

    expect(onlyDelivery()->next_attempt_at->getTimestamp())->toBe(now()->addSeconds(120)->getTimestamp());
})->with([429, 502, 504]);

it('reads retry-after as an http date', function () {
    Http::fake(['*' => Http::response(null, 429, ['Retry-After' => now()->addMinutes(10)->toRfc7231String()])]);

    Switchboard::emit('invoice.paid');
    relayNow();

    expect(onlyDelivery()->next_attempt_at->getTimestamp())->toBe(now()->addMinutes(10)->getTimestamp());
});

it('ignores retry-after on answers that are not a request to slow down', function () {
    Http::fake(['*' => Http::response(null, 500, ['Retry-After' => '3600'])]);

    Switchboard::emit('invoice.paid');
    relayNow();

    expect(onlyDelivery()->next_attempt_at->getTimestamp())->toBe(now()->addSeconds(5)->getTimestamp());
});

it('fails for good once the schedule is spent, and says so', function () {
    Event::fake([OutboxDeliveryFailed::class]);
    Http::fake(['*' => Http::response(null, 503)]);

    Switchboard::emit('invoice.paid');

    foreach (RetrySchedule::DEFAULT as $wait) {
        relayNow();
        $this->travel($wait)->seconds();
    }

    relayNow();

    $delivery = onlyDelivery();

    Http::assertSentCount(10);

    expect($delivery->isFailed())->toBeTrue()
        ->and($delivery->attempts)->toBe(10)
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->last_status)->toBe(503);

    Event::assertDispatchedTimes(OutboxDeliveryFailed::class, 1);
    Event::assertDispatched(OutboxDeliveryFailed::class, fn(OutboxDeliveryFailed $event): bool => $event->delivery->is($delivery));

    // Never attempted again.
    $this->travel(1)->days();
    relayNow();
    Http::assertSentCount(10);
});

it('keeps sending new messages to an endpoint after one delivery failed for good', function () {
    config(['switchboard.outbox.retry_schedule' => []]);
    Http::fakeSequence()->push(null, 500)->push(null, 204);

    Switchboard::emit('invoice.paid');
    relayNow();

    Switchboard::emit('invoice.paid');
    relayNow();

    expect(Delivery::query()->failed()->count())->toBe(1)
        ->and(Delivery::query()->delivered()->count())->toBe(1);
});

it('waits in the database, never on the queue', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(null, 500)]);

    Switchboard::emit('invoice.paid');
    relayNow();

    Queue::assertPushed(AttemptDelivery::class, fn(AttemptDelivery $job): bool => $job->delay === null);
});

it('sends with the configured timeout', function () {
    config(['switchboard.outbox.timeout' => 42]);

    $timeout = null;

    Http::fake(function ($request, array $options) use (&$timeout) {
        $timeout = $options['timeout'] ?? null;

        return Http::response();
    });

    Switchboard::emit('invoice.paid');
    relayNow();

    expect($timeout)->toBe(42);
});
