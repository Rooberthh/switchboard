<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Drivers\HmacDriver;
use Rooberthh\Switchboard\Drivers\StandardWebhooksDriver;
use Rooberthh\Switchboard\Tests\Fixtures\AcmeHexDriver;
use Rooberthh\Switchboard\Tests\Fixtures\AcmeStandardWebhooksDriver;
use Rooberthh\Switchboard\Tests\Fixtures\StandardWebhooksVector as Vector;
use Rooberthh\Switchboard\Tests\Fixtures\StripeDriver;

function signedRequest(array $headers = [], string $body = Vector::PAYLOAD): Request
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return Request::create('/webhooks/acme', 'POST', server: $server, content: $body);
}

function vectorRequest(array $overrides = [], string $body = Vector::PAYLOAD): Request
{
    return signedRequest(array_merge([
        'webhook-id' => Vector::ID,
        'webhook-timestamp' => (string) Vector::TIMESTAMP,
        'webhook-signature' => Vector::SIGNATURE,
    ], $overrides), $body);
}

beforeEach(function () {
    config(['switchboard.providers.acme.secret' => Vector::SECRET]);

    // The vector's timestamp is the clock it was signed against.
    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('verifies the standard webhooks reference vector', function () {
    expect((new AcmeStandardWebhooksDriver())->verify(vectorRequest()))->toBeTrue();
});

it('rejects the reference vector with a tampered body', function () {
    expect((new AcmeStandardWebhooksDriver())->verify(vectorRequest(body: '{"test": 2432232315}')))->toBeFalse();
});

it('rejects the reference vector signed with the wrong secret', function () {
    config(['switchboard.providers.acme.secret' => 'whsec_' . base64_encode('not the secret')]);

    expect((new AcmeStandardWebhooksDriver())->verify(vectorRequest()))->toBeFalse();
});

it('rejects a stale timestamp', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP + 301));

    expect((new AcmeStandardWebhooksDriver())->verify(vectorRequest()))->toBeFalse();
});

it('rejects a timestamp too far in the future', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP - 301));

    expect((new AcmeStandardWebhooksDriver())->verify(vectorRequest()))->toBeFalse();
});

it('accepts a timestamp inside the tolerance on either side', function () {
    $driver = new AcmeStandardWebhooksDriver();

    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP + 299));
    expect($driver->verify(vectorRequest()))->toBeTrue();

    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP - 299));
    expect($driver->verify(vectorRequest()))->toBeTrue();
});

it('takes the tolerance from configuration', function () {
    config(['switchboard.inbox.tolerance' => 3600]);

    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP + 1800));

    expect((new AcmeStandardWebhooksDriver())->verify(vectorRequest()))->toBeTrue();
});

it('rejects a signature in a scheme it does not accept rather than passing it', function () {
    // The same signature, downgraded to a version this driver never signs for.
    $downgraded = 'v0,' . substr(Vector::SIGNATURE, 3);

    expect((new AcmeStandardWebhooksDriver())->verify(vectorRequest(['webhook-signature' => $downgraded])))->toBeFalse();
});

it('rejects a request presenting no signature at all', function () {
    expect((new AcmeStandardWebhooksDriver())->verify(vectorRequest(['webhook-signature' => ''])))->toBeFalse();
});

it('rejects a request presenting no timestamp at all', function () {
    expect((new AcmeStandardWebhooksDriver())->verify(vectorRequest(['webhook-timestamp' => ''])))->toBeFalse();
});

it('verifies a signature carried alongside ones it cannot read', function () {
    $header = 'v0,ignored ' . Vector::SIGNATURE;

    expect((new AcmeStandardWebhooksDriver())->verify(vectorRequest(['webhook-signature' => $header])))->toBeTrue();
});

it('rejects everything when no secret is configured', function () {
    config(['switchboard.providers.acme.secret' => null]);

    expect((new AcmeStandardWebhooksDriver())->verify(vectorRequest()))->toBeFalse();
});

it('reads the secret from a conventional per-provider config location', function () {
    config(['switchboard.providers.acme.secret' => null, 'switchboard.providers.other.secret' => Vector::SECRET]);

    $elsewhere = new class extends AcmeStandardWebhooksDriver {
        protected function provider(): string
        {
            return 'other';
        }
    };

    expect($elsewhere->verify(vectorRequest()))->toBeTrue();
});

it('lets a subclass take its secret from somewhere else entirely', function () {
    config(['switchboard.providers.acme.secret' => null]);

    $perTenant = new class extends AcmeStandardWebhooksDriver {
        protected function secret(): string
        {
            return Vector::SECRET;
        }
    };

    expect($perTenant->verify(vectorRequest()))->toBeTrue();
});

it('carries a provider that signs the body alone, hex encoded', function () {
    $body = '{"id":"evt_1","type":"invoice.paid"}';
    $signature = hash_hmac('sha256', $body, Vector::SECRET);

    config(['switchboard.providers.acme.secret' => Vector::SECRET]);

    $request = signedRequest([
        'x-acme-signature' => $signature,
        'x-acme-timestamp' => (string) Vector::TIMESTAMP,
    ], $body);

    expect((new AcmeHexDriver())->verify($request))->toBeTrue();

    $tampered = signedRequest([
        'x-acme-signature' => $signature,
        'x-acme-timestamp' => (string) Vector::TIMESTAMP,
    ], '{"id":"evt_1","type":"invoice.refunded"}');

    expect((new AcmeHexDriver())->verify($tampered))->toBeFalse();
});

it('compares signatures in constant time', function () {
    // Timing cannot be asserted, so assert the only thing that guarantees it:
    // the comparison the base class performs.
    $source = file_get_contents((new ReflectionClass(HmacDriver::class))->getFileName());

    expect($source)->toContain('hash_equals(')
        ->and($source)->not->toContain('=== $candidate')
        ->and($source)->not->toContain('== $candidate');
});

it('signs over the raw request body, never a re-encoded copy of it', function () {
    // Re-encoding this body changes its bytes but not its meaning.
    $body = '{"test":  2432232314}';

    $key = base64_decode(substr(Vector::SECRET, 6));
    $signature = 'v1,' . base64_encode(hash_hmac('sha256', Vector::ID . '.' . Vector::TIMESTAMP . '.' . $body, $key, true));

    $request = vectorRequest(['webhook-signature' => $signature], $body);

    expect((new AcmeStandardWebhooksDriver())->verify($request))->toBeTrue();
});

it('is verifiable without the standard webhooks base class', function () {
    expect(is_subclass_of(AcmeHexDriver::class, StandardWebhooksDriver::class))->toBeFalse()
        ->and(is_subclass_of(AcmeHexDriver::class, HmacDriver::class))->toBeTrue();
});

it("carries the readme's stripe recipe", function () {
    $secret = 'whsec_a_stripe_endpoint_secret';
    $body = '{"id":"evt_1","type":"invoice.paid","created":' . Vector::TIMESTAMP . ',"data":{"object":{"customer":"cus_12345"}}}';

    config(['switchboard.providers.stripe.secret' => $secret]);

    $signature = hash_hmac('sha256', Vector::TIMESTAMP . '.' . $body, $secret);
    $header = 't=' . Vector::TIMESTAMP . ',v1=' . $signature;

    $driver = new StripeDriver();

    expect($driver->verify(signedRequest(['stripe-signature' => $header], $body)))->toBeTrue();

    // The same signature offered under the scheme Stripe has retired.
    $downgraded = 't=' . Vector::TIMESTAMP . ',v0=' . $signature;
    expect($driver->verify(signedRequest(['stripe-signature' => $downgraded], $body)))->toBeFalse();

    // Tampering with the body after signing.
    expect($driver->verify(signedRequest(['stripe-signature' => $header], $body . ' ')))->toBeFalse();

    $data = $driver->normalize(signedRequest(['stripe-signature' => $header], $body));

    expect($data->eventId)->toBe('evt_1')
        ->and($data->eventType)->toBe('invoice.paid')
        ->and($data->subject)->toBe('cus_12345')
        ->and($data->occurredAt?->getTimestamp())->toBe(Vector::TIMESTAMP);
});
