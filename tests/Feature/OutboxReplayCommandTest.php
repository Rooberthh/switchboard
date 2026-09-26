<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Rooberthh\Switchboard\Actions\ReplayDeliveryAction;
use Rooberthh\Switchboard\Exceptions\IllegalTransition;
use Rooberthh\Switchboard\Models\Delivery;
use Rooberthh\Switchboard\Models\Endpoint;
use Rooberthh\Switchboard\Switchboard;

beforeEach(function () {
    Http::preventStrayRequests();

    // One attempt only, so a failure is final at once.
    config(['switchboard.outbox.retry_schedule' => []]);
});

function failingEndpoint(string $host): Endpoint
{
    return Endpoint::query()->create(['url' => "https://{$host}/hook", 'event_types' => ['invoice.paid']]);
}

it('sends a failed delivery again once replayed, byte for byte to the same url', function () {
    Http::fakeSequence()->push(null, 500)->push(null, 204);

    $endpoint = failingEndpoint('hooks.acme.test');
    $message = Switchboard::emit('invoice.paid', ['amount' => 1000]);
    $this->artisan('switchboard:outbox:relay');

    // The receiver moved; the delivery keeps the url it was made with.
    $endpoint->update(['url' => 'https://elsewhere.acme.test/hook']);

    $this->artisan('switchboard:outbox:replay')
        ->expectsOutputToContain('Replayed 1 failed outbox delivery.')
        ->assertSuccessful();

    $this->artisan('switchboard:outbox:relay');

    $recorded = collect(Http::recorded())->map(fn(array $pair) => $pair[0]);

    expect($recorded)->toHaveCount(2)
        ->and($recorded[1]->url())->toBe('https://hooks.acme.test/hook')
        ->and($recorded[1]->body())->toBe($recorded[0]->body())
        ->and($recorded[1]->body())->toBe($message->body)
        ->and(Delivery::query()->sole()->isDelivered())->toBeTrue();
});

it('returns a failed delivery to pending, due now, with its schedule started over', function () {
    Http::fake(['*' => Http::response(null, 500)]);
    $this->freezeTime();

    failingEndpoint('hooks.acme.test');
    Switchboard::emit('invoice.paid');
    $this->artisan('switchboard:outbox:relay');

    $this->artisan('switchboard:outbox:replay')->assertSuccessful();

    $delivery = Delivery::query()->sole();

    expect($delivery->isPending())->toBeTrue()
        ->and($delivery->attempt_count)->toBe(0)
        ->and($delivery->next_attempt_at->getTimestamp())->toBe(now()->getTimestamp());
});

it('limits replay to one endpoint when asked', function () {
    Http::fake(['*' => Http::response(null, 500)]);

    $mine = failingEndpoint('mine.acme.test');
    failingEndpoint('theirs.acme.test');
    Switchboard::emit('invoice.paid');
    $this->artisan('switchboard:outbox:relay');

    $this->artisan('switchboard:outbox:replay', ['--endpoint' => (string) $mine->id])
        ->expectsOutputToContain('Replayed 1 failed')
        ->assertSuccessful();

    expect(Delivery::query()->where('endpoint_id', (string) $mine->id)->sole()->isPending())->toBeTrue()
        ->and(Delivery::query()->where('endpoint_id', '!=', (string) $mine->id)->sole()->isFailed())->toBeTrue();
});

it('refuses an endpoint option given without a value rather than replaying everything', function () {
    Http::fake(['*' => Http::response(null, 500)]);

    failingEndpoint('hooks.acme.test');
    Switchboard::emit('invoice.paid');
    $this->artisan('switchboard:outbox:relay');

    $this->artisan('switchboard:outbox:replay', ['--endpoint' => ''])->assertFailed();

    expect(Delivery::query()->sole()->isFailed())->toBeTrue();
});

it('never replays a delivered delivery', function () {
    Http::fake(['*' => Http::response()]);

    failingEndpoint('hooks.acme.test');
    Switchboard::emit('invoice.paid');
    $this->artisan('switchboard:outbox:relay');

    $this->artisan('switchboard:outbox:replay')
        ->expectsOutputToContain('Replayed 0 failed outbox deliveries.')
        ->assertSuccessful();

    expect(fn() => app(ReplayDeliveryAction::class)->execute(Delivery::query()->sole()))
        ->toThrow(IllegalTransition::class);

    Http::assertSentCount(1);
});

it('pages past more failures than fit in one chunk', function () {
    Http::fake(['*' => Http::response(null, 500)]);

    failingEndpoint('hooks.acme.test');

    foreach (range(1, 1005) as $i) {
        Switchboard::emit('invoice.paid');
    }

    $this->artisan('switchboard:outbox:relay');

    expect(Delivery::query()->failed()->count())->toBe(1005);

    $this->artisan('switchboard:outbox:replay')
        ->expectsOutputToContain('Replayed 1005 failed')
        ->assertSuccessful();

    expect(Delivery::query()->failed()->count())->toBe(0);
});
