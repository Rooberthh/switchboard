<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Rooberthh\Switchboard\Actions\AttemptDeliveryAction;
use Rooberthh\Switchboard\Jobs\AttemptDelivery;
use Rooberthh\Switchboard\Models\Delivery;
use Rooberthh\Switchboard\Models\DeliveryAttempt;
use Rooberthh\Switchboard\Models\Endpoint;
use Rooberthh\Switchboard\Support\RetrySchedule;
use Rooberthh\Switchboard\Support\SsrfGuard;
use Rooberthh\Switchboard\Switchboard;

beforeEach(function () {
    $this->freezeTime();

    Http::preventStrayRequests();

    // No jitter, so waits are exact.
    app()->instance(RetrySchedule::class, new RetrySchedule(random: fn(): float => 0.0));
});

function attemptedEndpoint(string $url = 'https://hooks.acme.test/hook'): Endpoint
{
    return Endpoint::query()->create(['url' => $url, 'event_types' => ['invoice.paid']]);
}

function relayAttempts(): void
{
    test()->artisan('switchboard:outbox:relay')->assertSuccessful();
}

/**
 * Emit, relay once, and return the one delivery with its attempts.
 */
function attemptOnce(): Delivery
{
    attemptedEndpoint();
    Switchboard::emit('invoice.paid', ['amount' => 1000]);
    relayAttempts();

    return Delivery::query()->sole();
}

it('records a successful attempt with what the receiver answered', function () {
    Http::fake(['*' => Http::response('{"received":true}', 200)]);

    $delivery = attemptOnce();
    $attempt = $delivery->attempts()->sole();

    expect($attempt->status)->toBe(200)
        ->and($attempt->error)->toBeNull()
        ->and($attempt->response_excerpt)->toBe('{"received":true}')
        ->and($attempt->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($attempt->created_at->getTimestamp())->toBe(now()->getTimestamp())
        ->and($delivery->latestAttempt->is($attempt))->toBeTrue();
});

it('keeps every attempt, each with its own answer, in the order they were made', function () {
    Http::fakeSequence()
        ->push('signature mismatch', 401)
        ->pushFailedConnection()
        ->push(null, 503)
        ->push(null, 204);

    attemptedEndpoint();
    Switchboard::emit('invoice.paid');

    foreach ([0, 5, 300, 1800] as $wait) {
        $this->travel($wait)->seconds();
        relayAttempts();
    }

    $delivery = Delivery::query()->sole();
    $attempts = $delivery->attempts()->orderBy('id')->get();

    expect($delivery->isDelivered())->toBeTrue()
        ->and($delivery->attempt_count)->toBe(4)
        ->and($attempts->pluck('status')->all())->toBe([401, null, 503, 204])
        ->and($attempts[0]->error)->toBe('The endpoint answered 401.')
        ->and($attempts[0]->response_excerpt)->toBe('signature mismatch')
        ->and($attempts[1]->error)->not->toBeNull()
        ->and($attempts[1]->response_excerpt)->toBeNull()
        ->and($attempts[3]->error)->toBeNull();
});

it('keeps the summary on the delivery in step with its latest attempt', function () {
    Http::fake(['*' => Http::response('down for maintenance', 500)]);

    $delivery = attemptOnce();

    expect($delivery->last_status)->toBe($delivery->latestAttempt->status)
        ->and($delivery->last_error)->toBe($delivery->latestAttempt->error);
});

it('records all ten attempts of a delivery that fails for good', function () {
    Http::fake(['*' => Http::response(null, 503)]);

    attemptedEndpoint();
    Switchboard::emit('invoice.paid');

    foreach (RetrySchedule::DEFAULT as $wait) {
        relayAttempts();
        $this->travel($wait)->seconds();
    }

    relayAttempts();

    $delivery = Delivery::query()->sole();

    expect($delivery->isFailed())->toBeTrue()
        ->and($delivery->attempts()->count())->toBe(10)
        ->and($delivery->attempts()->where('status', 503)->count())->toBe(10);
});

it('records a timeout as an attempt with no status', function () {
    Http::fake(fn() => throw new ConnectionException('cURL error 28: Operation timed out'));

    $attempt = attemptOnce()->attempts()->sole();

    expect($attempt->status)->toBeNull()
        ->and($attempt->error)->toContain('timed out')
        ->and($attempt->response_excerpt)->toBeNull();
});

it('records a refused private address as an attempt, with nothing sent', function () {
    Http::fake();
    app()->instance(SsrfGuard::class, new SsrfGuard(fn(string $host): array => ['10.0.0.5']));

    $delivery = attemptOnce();
    $attempt = $delivery->attempts()->sole();

    Http::assertNothingSent();

    expect($delivery->attempt_count)->toBe(1)
        ->and($attempt->status)->toBeNull()
        ->and($attempt->error)->toContain('not a public address');
});

it('records a redirect as an attempt, without following it', function () {
    Http::fake(['*' => Http::response(null, 302, ['Location' => 'http://169.254.169.254/'])]);

    expect(attemptOnce()->attempts()->sole()->status)->toBe(302);

    Http::assertSentCount(1);
});

it('records the 410 gone that disabled the endpoint', function () {
    Http::fake(['*' => Http::response('this endpoint was removed', 410)]);

    $attempt = attemptOnce()->attempts()->sole();

    expect($attempt->status)->toBe(410)
        ->and($attempt->error)->toContain('disabled')
        ->and($attempt->response_excerpt)->toBe('this endpoint was removed');
});

it('records no attempt when the endpoint is gone before anything is sent', function () {
    Queue::fake();
    Http::fake();

    $endpoint = attemptedEndpoint();
    Switchboard::emit('invoice.paid');
    relayAttempts();

    $endpoint->delete();

    (new AttemptDelivery(Delivery::query()->sole()->id))->handle(app(AttemptDeliveryAction::class));

    $delivery = Delivery::query()->sole();

    Http::assertNothingSent();

    expect($delivery->isFailed())->toBeTrue()
        ->and($delivery->attempt_count)->toBe(0)
        ->and($delivery->last_error)->toContain('no longer exists')
        ->and($delivery->attempts()->count())->toBe(0);
});

it('keeps the attempts made before a replay, and adds the replayed ones after them', function () {
    config(['switchboard.outbox.retry_schedule' => []]);
    Http::fakeSequence()->push('broken', 500)->push(null, 204);

    attemptedEndpoint();
    Switchboard::emit('invoice.paid');
    relayAttempts();

    $this->artisan('switchboard:outbox:replay')->assertSuccessful();

    expect(Delivery::query()->sole()->attempt_count)->toBe(0)
        ->and(DeliveryAttempt::query()->count())->toBe(1);

    relayAttempts();

    $delivery = Delivery::query()->sole();

    expect($delivery->isDelivered())->toBeTrue()
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->attempts()->orderBy('id')->pluck('status')->all())->toBe([500, 204]);
});

it('keeps only the first kilobyte of the answer', function () {
    Http::fake(['*' => Http::response(str_repeat('a', 5000), 500)]);

    expect(attemptOnce()->attempts()->sole()->response_excerpt)->toBe(str_repeat('a', 1024));
});

it('stores an answer that is not valid utf-8 as valid utf-8, without nul bytes', function () {
    // A four-byte character cut by the limit, then bytes that are no text at all.
    $body = str_repeat('a', 1022) . '😀';

    Http::fakeSequence()->push($body, 500)->push("\xff\xfe\0binary\0", 500);

    attemptedEndpoint();
    Switchboard::emit('invoice.paid');
    relayAttempts();
    $this->travel(5)->seconds();
    relayAttempts();

    [$cut, $binary] = DeliveryAttempt::query()->orderBy('id')->pluck('response_excerpt')->all();

    expect(mb_check_encoding($cut, 'UTF-8'))->toBeTrue()
        ->and($cut)->toStartWith(str_repeat('a', 1022))
        ->and(mb_check_encoding($binary, 'UTF-8'))->toBeTrue()
        ->and($binary)->not->toContain("\0")
        ->and($binary)->toContain('binary');
});

it('stores no excerpt for an empty answer', function () {
    Http::fake(['*' => Http::response(null, 204)]);

    expect(attemptOnce()->attempts()->sole()->response_excerpt)->toBeNull();
});

it('takes its table name from configuration', function () {
    config([
        'switchboard.tables.outbox_messages' => 'acme_outbox',
        'switchboard.tables.deliveries' => 'acme_deliveries',
        'switchboard.tables.delivery_attempts' => 'acme_attempts',
    ]);

    expect((new DeliveryAttempt())->getTable())->toBe('acme_attempts');

    foreach (['000001_create_switchboard_outbox_messages', '000003_create_switchboard_deliveries', '000004_create_switchboard_delivery_attempts'] as $file) {
        $migration = require __DIR__ . "/../../database/migrations/0001_01_01_{$file}_table.php";
        $migration->up();
    }

    expect(Schema::hasTable('acme_attempts'))->toBeTrue();
});
