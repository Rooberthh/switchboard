<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Rooberthh\Switchboard\Models\Delivery;
use Rooberthh\Switchboard\Models\Endpoint;
use Rooberthh\Switchboard\Support\SsrfGuard;
use Rooberthh\Switchboard\Switchboard;

beforeEach(function () {
    Http::preventStrayRequests();
});

function deliverTo(string $url): Delivery
{
    Endpoint::query()->create(['url' => $url, 'event_types' => ['invoice.paid']]);
    Switchboard::emit('invoice.paid', ['amount' => 1000]);

    test()->artisan('switchboard:outbox:relay')->assertSuccessful();

    return Delivery::query()->sole();
}

function resolvingTo(string ...$addresses): void
{
    app()->instance(SsrfGuard::class, new SsrfGuard(fn(string $host): array => array_values($addresses)));
}

it('does not deliver to a host that resolves to a private address', function (string $address) {
    Http::fake();
    resolvingTo($address);

    $delivery = deliverTo('https://internal.acme.test/hook');

    Http::assertNothingSent();

    expect($delivery->isDelivered())->toBeFalse()
        ->and($delivery->last_error)->toContain('not a public address');
})->with(['10.0.0.5', '127.0.0.1', '169.254.169.254', '::1']);

it('does not deliver to a private ip written into the url', function () {
    Http::fake();

    $delivery = deliverTo('http://169.254.169.254/latest/meta-data');

    Http::assertNothingSent();

    expect($delivery->isDelivered())->toBeFalse();
});

it('connects to the address it checked, so rebinding cannot redirect the connection', function () {
    $answers = ['93.184.215.14', '10.0.0.5'];
    app()->instance(SsrfGuard::class, new SsrfGuard(function () use (&$answers): array {
        return [array_shift($answers)];
    }));

    $options = null;

    // A fake's stub sees the options the request would be sent with.
    Http::fake(function ($request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response();
    });

    deliverTo('https://rebind.acme.test/hook');

    expect($options['curl'][CURLOPT_RESOLVE] ?? null)->toBe(['rebind.acme.test:443:93.184.215.14'])
        ->and($options['allow_redirects'])->toBeFalse();
});

it('treats a redirect as a failure and never requests its target', function () {
    Http::fake([
        'hooks.acme.test/*' => Http::response(null, 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
    ]);

    $delivery = deliverTo('https://hooks.acme.test/hook');

    Http::assertSentCount(1);
    Http::assertNotSent(fn($request): bool => str_contains($request->url(), '169.254.169.254'));

    expect($delivery->isDelivered())->toBeFalse()
        ->and($delivery->last_status)->toBe(302);
});

it('delivers to an allowed host even when it resolves privately', function () {
    config(['switchboard.outbox.allowed_hosts' => ['localhost']]);
    resolvingTo('127.0.0.1');
    Http::fake(['localhost:8000/*' => Http::response()]);

    $delivery = deliverTo('http://localhost:8000/hook');

    Http::assertSentCount(1);

    expect($delivery->isDelivered())->toBeTrue();
});
