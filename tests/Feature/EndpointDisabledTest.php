<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Rooberthh\Switchboard\Contracts\Endpoints;
use Rooberthh\Switchboard\Events\EndpointDisabled;
use Rooberthh\Switchboard\Jobs\AttemptDelivery;
use Rooberthh\Switchboard\Models\Delivery;
use Rooberthh\Switchboard\Models\Endpoint;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Actions\AttemptDeliveryAction;
use Rooberthh\Switchboard\Outbox\EndpointData;

beforeEach(function () {
    Http::preventStrayRequests();
});

function gone(): Endpoint
{
    return Endpoint::query()->create(['url' => 'https://gone.acme.test/hook', 'event_types' => ['invoice.paid']]);
}

it('disables the endpoint and ends the delivery on 410 gone', function () {
    Http::fake(['gone.acme.test/*' => Http::response(null, 410)]);

    $endpoint = gone();
    Switchboard::emit('invoice.paid');
    $this->artisan('switchboard:outbox:relay');

    $delivery = Delivery::query()->sole();

    expect($endpoint->refresh()->isDisabled())->toBeTrue()
        ->and($delivery->isFailed())->toBeTrue()
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->last_status)->toBe(410);
});

it('announces the disabled endpoint after commit', function () {
    Http::fake(['*' => Http::response(null, 410)]);

    $seen = [];
    Event::listen(EndpointDisabled::class, function (EndpointDisabled $event) use (&$seen): void {
        $seen[] = [$event->endpoint->id, $event->endpoint->url];
    });

    $endpoint = gone();
    Switchboard::emit('invoice.paid');
    $this->artisan('switchboard:outbox:relay');

    expect($seen)->toBe([[(string) $endpoint->id, 'https://gone.acme.test/hook']]);
});

it('creates no new deliveries for a disabled endpoint', function () {
    Http::fake(['*' => Http::response(null, 410)]);

    gone();
    Switchboard::emit('invoice.paid');
    $this->artisan('switchboard:outbox:relay');

    Switchboard::emit('invoice.paid');
    $this->artisan('switchboard:outbox:relay');

    expect(Delivery::query()->count())->toBe(1);

    Http::assertSentCount(1);
});

it('sends none of a disabled endpoint\'s other pending deliveries', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(null, 410)]);

    gone();
    Switchboard::emit('invoice.paid');
    Switchboard::emit('invoice.paid');
    $this->artisan('switchboard:outbox:relay');

    [$first, $second] = Delivery::query()->orderBy('id')->get()->all();

    (new AttemptDelivery($first->id))->handle(app(AttemptDeliveryAction::class));
    (new AttemptDelivery($second->id))->handle(app(AttemptDeliveryAction::class));

    Http::assertSentCount(1);

    expect($second->refresh()->isFailed())->toBeTrue()
        ->and($second->attempt_count)->toBe(0);
});

it('disables through the endpoints contract, so custom storage hears about it', function () {
    Http::fake(['*' => Http::response(null, 410)]);

    $store = new class implements Endpoints {
        /** @var list<string> */
        public array $disabled = [];

        public function subscribedTo(string $eventType): iterable
        {
            return [new EndpointData('ep_1', 'https://gone.acme.test/hook', Endpoint::generateSecret())];
        }

        public function find(string $id): ?EndpointData
        {
            return in_array($id, $this->disabled, true) ? null : $this->subscribedTo('')[0];
        }

        public function disable(string $id): void
        {
            $this->disabled[] = $id;
        }
    };

    app()->instance(Endpoints::class, $store);

    Switchboard::emit('invoice.paid');
    $this->artisan('switchboard:outbox:relay');

    expect($store->disabled)->toBe(['ep_1']);
});
