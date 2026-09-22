<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Rooberthh\Switchboard\Contracts\Endpoints;
use Rooberthh\Switchboard\Events\OutboxDeliverySucceeded;
use Rooberthh\Switchboard\Jobs\AttemptDelivery;
use Rooberthh\Switchboard\Models\Delivery;
use Rooberthh\Switchboard\Models\Endpoint;
use Rooberthh\Switchboard\Models\OutboxMessage;
use Rooberthh\Switchboard\Outbox\DatabaseEndpoints;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Verification\StandardWebhooks;
use Rooberthh\Switchboard\Actions\AttemptDeliveryAction;
use Rooberthh\Switchboard\Outbox\EndpointData;

beforeEach(function () {
    Http::preventStrayRequests();
});

function endpoint(array $eventTypes = ['invoice.paid'], string $url = 'https://hooks.acme.test/switchboard', array $attributes = []): Endpoint
{
    return Endpoint::query()->create(['url' => $url, 'event_types' => $eventTypes, ...$attributes]);
}

function relay(): void
{
    test()->artisan('switchboard:outbox:relay')->assertSuccessful();
}

/**
 * The request the fake received, as the inbox would see it.
 * @param ClientRequest $request
 */
function asInboundRequest(ClientRequest $request): Request
{
    $server = [];

    foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $header) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $header))] = $request->header($header)[0] ?? '';
    }

    return Request::create($request->url(), 'POST', server: $server, content: $request->body());
}

it('delivers an emitted message to a subscribed endpoint, end to end', function () {
    Http::fake(['hooks.acme.test/*' => Http::response(null, 204)]);

    $endpoint = endpoint();
    $message = Switchboard::emit('invoice.paid', ['amount' => 1000]);

    relay();

    Http::assertSentCount(1);
    Http::assertSent(fn(ClientRequest $request): bool => $request->url() === 'https://hooks.acme.test/switchboard'
        && $request->method() === 'POST'
        && $request->body() === $message->body
        && $request->header('webhook-id')[0] === $message->event_id
        && $request->header('Content-Type')[0] === 'application/json');

    $delivery = Delivery::query()->sole();

    expect($delivery->endpoint_id)->toBe((string) $endpoint->id)
        ->and($delivery->isDelivered())->toBeTrue()
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->last_status)->toBe(204)
        ->and($message->refresh()->relayed_at)->not->toBeNull();
});

it('signs every request so a standard webhooks receiver accepts it', function () {
    $this->freezeTime();

    Http::fake(['*' => Http::response()]);

    $endpoint = endpoint();
    Switchboard::emit('invoice.paid', ['amount' => 1000]);

    relay();

    $sent = Http::recorded()[0][0];

    expect((int) $sent->header('webhook-timestamp')[0])->toBe(now()->getTimestamp())
        ->and($sent->header('webhook-signature')[0])->toStartWith('v1,')
        ->and((new StandardWebhooks($endpoint->secret))->verify(asInboundRequest($sent)))->toBeTrue()
        ->and((new StandardWebhooks(Endpoint::generateSecret()))->verify(asInboundRequest($sent)))->toBeFalse();
});

it('signs with the endpoint\'s current secret, not one captured when the delivery was made', function () {
    Queue::fake();
    Http::fake(['*' => Http::response()]);

    $endpoint = endpoint();
    Switchboard::emit('invoice.paid');
    relay();

    // Rotated after the delivery exists, before it is attempted.
    $endpoint->update(['secret' => $rotated = Endpoint::generateSecret()]);

    (new AttemptDelivery(Delivery::query()->sole()->id))->handle(app(AttemptDeliveryAction::class));

    expect((new StandardWebhooks($rotated))->verify(asInboundRequest(Http::recorded()[0][0])))->toBeTrue();
});

it('makes one delivery per subscribed endpoint and none for the rest', function () {
    Http::fake(['*' => Http::response()]);

    endpoint(['invoice.paid'], 'https://one.acme.test/hook');
    endpoint(['invoice.paid', 'invoice.voided'], 'https://two.acme.test/hook');
    endpoint(['invoice.voided'], 'https://three.acme.test/hook');
    endpoint(['invoice'], 'https://four.acme.test/hook');

    Switchboard::emit('invoice.paid');
    relay();

    expect(Delivery::query()->pluck('url')->sort()->values()->all())->toBe([
        'https://one.acme.test/hook',
        'https://two.acme.test/hook',
    ]);

    Http::assertSentCount(2);
});

it('relays a message nobody subscribes to, with no deliveries', function () {
    $message = Switchboard::emit('invoice.paid');

    relay();

    expect($message->refresh()->relayed_at)->not->toBeNull()
        ->and(Delivery::query()->count())->toBe(0);
});

it('never makes a second delivery of one message to one endpoint', function () {
    Http::fake(['*' => Http::response()]);

    endpoint();
    $message = Switchboard::emit('invoice.paid');

    relay();
    relay();

    // Even a relay that lost track of relayed_at is stopped by the index.
    $message->forceFill(['relayed_at' => null])->save();
    relay();

    expect(Delivery::query()->count())->toBe(1);

    Http::assertSentCount(1);
});

it('creates a message\'s deliveries and marks it relayed together', function () {
    // An endpoint store that dies partway must leave the message unrelayed,
    // with no deliveries, for the next run — never half relayed.
    app()->instance(Endpoints::class, new class implements Endpoints {
        public function subscribedTo(string $eventType): iterable
        {
            yield new EndpointData('1', 'https://one.acme.test', Endpoint::generateSecret());

            throw new RuntimeException('the endpoint store went away');
        }

        public function find(string $id): ?EndpointData
        {
            return null;
        }

        public function disable(string $id): void {}
    });

    $message = Switchboard::emit('invoice.paid');

    expect(fn() => relay())->toThrow(RuntimeException::class);

    expect($message->refresh()->relayed_at)->toBeNull()
        ->and(Delivery::query()->count())->toBe(0);
});

it('snapshots the endpoint url on the delivery', function () {
    Queue::fake();

    $endpoint = endpoint(url: 'https://old.acme.test/hook');
    Switchboard::emit('invoice.paid');
    relay();

    $endpoint->update(['url' => 'https://new.acme.test/hook']);

    expect(Delivery::query()->sole()->url)->toBe('https://old.acme.test/hook');
});

it('queues only deliveries that are due, and leases what it queues', function () {
    Queue::fake();
    $this->freezeTime();

    endpoint();
    Switchboard::emit('invoice.paid');

    relay();

    Queue::assertPushed(AttemptDelivery::class, 1);

    $delivery = Delivery::query()->sole();

    expect($delivery->next_attempt_at->getTimestamp())->toBe(now()->addSeconds(300)->getTimestamp());

    // Still leased: the next run leaves it alone.
    relay();
    Queue::assertPushed(AttemptDelivery::class, 1);

    // The lease has run out, so the job is presumed lost and queued again.
    $this->travel(301)->seconds();
    relay();
    Queue::assertPushed(AttemptDelivery::class, 2);
});

it('puts the lease back when the queue will not take the job', function () {
    config(['queue.default' => 'database']);
    Schema::drop('jobs');

    endpoint();
    Switchboard::emit('invoice.paid');

    expect(fn() => relay())->toThrow(Exception::class);

    $delivery = Delivery::query()->sole();

    expect($delivery->next_attempt_at->lessThanOrEqualTo(now()))->toBeTrue()
        ->and($delivery->isPending())->toBeTrue();
});

it('announces a delivery that succeeded, after commit', function () {
    Http::fake(['*' => Http::response()]);

    $seen = [];
    Event::listen(OutboxDeliverySucceeded::class, function (OutboxDeliverySucceeded $event) use (&$seen): void {
        $seen[] = $event->delivery->id;
    });

    endpoint();
    Switchboard::emit('invoice.paid');
    relay();

    expect($seen)->toBe([Delivery::query()->sole()->id]);
});

it('skips a delivery that is no longer pending when its job runs', function () {
    Queue::fake();
    Http::fake();

    endpoint();
    Switchboard::emit('invoice.paid');
    relay();

    $delivery = Delivery::query()->sole();
    $delivery->forceFill(['delivered_at' => now()])->save();

    (new AttemptDelivery($delivery->id))->handle(app(AttemptDeliveryAction::class));

    Http::assertNothingSent();
});

it('generates a standard webhooks secret for a new endpoint and stores it encrypted', function () {
    $endpoint = endpoint();

    expect($endpoint->secret)->toStartWith('whsec_')
        ->and(strlen((string) base64_decode(substr($endpoint->secret, 6), true)))->toBe(32)
        ->and(endpoint()->secret)->not->toBe($endpoint->secret);

    $stored = DB::table('switchboard_endpoints')->where('id', $endpoint->id)->value('secret');

    expect($stored)->not->toContain($endpoint->secret)
        ->and(Crypt::decryptString($stored))->toBe($endpoint->secret)
        ->and($endpoint->toArray())->not->toHaveKey('secret');
});

it('binds the table-backed endpoints by default, and lets an application replace them', function () {
    expect(app(Endpoints::class))->toBeInstanceOf(DatabaseEndpoints::class);

    $own = new class implements Endpoints {
        public function subscribedTo(string $eventType): iterable
        {
            return [];
        }

        public function find(string $id): ?EndpointData
        {
            return null;
        }

        public function disable(string $id): void {}
    };

    app()->instance(Endpoints::class, $own);

    expect(app(Endpoints::class))->toBe($own);
});

it('keeps the endpoints contract to three methods', function () {
    expect(count((new ReflectionClass(Endpoints::class))->getMethods()))->toBeLessThanOrEqual(3);
});

it('does not relay a message that was rolled back', function () {
    endpoint();

    DB::beginTransaction();
    Switchboard::emit('invoice.paid');
    DB::rollBack();

    relay();

    expect(OutboxMessage::query()->count())->toBe(0)
        ->and(Delivery::query()->count())->toBe(0);
});
