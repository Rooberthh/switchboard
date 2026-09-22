<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Rooberthh\Switchboard\Contracts\Endpoints;
use Rooberthh\Switchboard\Models\Delivery;
use Rooberthh\Switchboard\Models\Endpoint;
use Rooberthh\Switchboard\Outbox\EndpointData;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Tests\Fixtures\InMemoryEndpoints;
use Rooberthh\Switchboard\Verification\StandardWebhooks;

beforeEach(function () {
    Http::preventStrayRequests();
});

function verifiesWith(string $secret, ClientRequest $sent): bool
{
    return (new StandardWebhooks($secret))->verify(Request::create($sent->url(), 'POST', server: [
        'HTTP_WEBHOOK_ID' => $sent->header('webhook-id')[0],
        'HTTP_WEBHOOK_TIMESTAMP' => $sent->header('webhook-timestamp')[0],
        'HTTP_WEBHOOK_SIGNATURE' => $sent->header('webhook-signature')[0],
    ], content: $sent->body()));
}

it('delivers through an application\'s own endpoint storage, end to end', function () {
    Http::fake(['*' => Http::response()]);

    $secret = Endpoint::generateSecret();

    $endpoints = new InMemoryEndpoints();
    $endpoints->add(new EndpointData('tenant-7:billing', 'https://billing.tenant7.test/hooks', $secret), ['invoice.paid']);
    $endpoints->add(new EndpointData('tenant-7:crm', 'https://crm.tenant7.test/hooks', Endpoint::generateSecret()), ['customer.created']);

    app()->instance(Endpoints::class, $endpoints);

    $message = Switchboard::emit('invoice.paid', ['amount' => 1000]);
    $this->artisan('switchboard:outbox:relay')->assertSuccessful();

    Http::assertSentCount(1);

    $sent = Http::recorded()[0][0];

    expect($sent->url())->toBe('https://billing.tenant7.test/hooks')
        ->and($sent->body())->toBe($message->body)
        ->and(verifiesWith($secret, $sent))->toBeTrue();
});

it('needs no row in switchboard\'s endpoints table', function () {
    Http::fake(['*' => Http::response()]);

    $endpoints = new InMemoryEndpoints();
    $endpoints->add(new EndpointData('not-a-number', 'https://hooks.acme.test/in', Endpoint::generateSecret()), ['invoice.paid']);
    app()->instance(Endpoints::class, $endpoints);

    Switchboard::emit('invoice.paid');
    $this->artisan('switchboard:outbox:relay');

    expect(Endpoint::query()->count())->toBe(0)
        ->and(Delivery::query()->sole()->endpoint_id)->toBe('not-a-number')
        ->and(Delivery::query()->sole()->isDelivered())->toBeTrue();
});

it('signs with a secret the application supplied itself', function () {
    Http::fake(['*' => Http::response()]);

    // Migrating an endpoint whose receiver already has its secret.
    $existing = 'whsec_' . base64_encode('an existing thirty-two byte key!');

    $endpoint = Endpoint::query()->create([
        'url' => 'https://hooks.acme.test/in',
        'event_types' => ['invoice.paid'],
        'secret' => $existing,
    ]);

    Switchboard::emit('invoice.paid');
    $this->artisan('switchboard:outbox:relay');

    expect($endpoint->refresh()->secret)->toBe($existing)
        ->and(verifiesWith($existing, Http::recorded()[0][0]))->toBeTrue();
});
