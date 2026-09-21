<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Exceptions\InvalidProviderSecret;
use Rooberthh\Switchboard\Tests\Fixtures\StandardWebhooksVector as Vector;
use Rooberthh\Switchboard\Tests\Fixtures\StripeVerification;
use Rooberthh\Switchboard\Verification\StandardWebhooks;

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

function verification(string $secret = Vector::SECRET, ?int $tolerance = null): StandardWebhooks
{
    return new StandardWebhooks($secret, $tolerance);
}

beforeEach(function () {
    // The vector's timestamp is the clock it was signed against.
    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('verifies the standard webhooks reference vector', function () {
    expect(verification()->verify(vectorRequest()))->toBeTrue();
});

it('rejects the reference vector with a tampered body', function () {
    expect(verification()->verify(vectorRequest(body: '{"test": 2432232315}')))->toBeFalse();
});

it('rejects the reference vector signed with the wrong secret', function () {
    expect(verification('whsec_' . base64_encode('not the secret'))->verify(vectorRequest()))->toBeFalse();
});

it('rejects a stale timestamp', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP + 301));

    expect(verification()->verify(vectorRequest()))->toBeFalse();
});

it('rejects a timestamp too far in the future', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP - 301));

    expect(verification()->verify(vectorRequest()))->toBeFalse();
});

it('accepts a timestamp inside the tolerance on either side', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP + 299));
    expect(verification()->verify(vectorRequest()))->toBeTrue();

    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP - 299));
    expect(verification()->verify(vectorRequest()))->toBeTrue();
});

it('takes the default tolerance from configuration', function () {
    config(['switchboard.inbox.tolerance' => 3600]);

    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP + 1800));

    expect(verification()->verify(vectorRequest()))->toBeTrue();
});

it('lets one provider set its own tolerance', function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP + 60));

    expect(verification(tolerance: 30)->verify(vectorRequest()))->toBeFalse()
        ->and(verification(tolerance: 90)->verify(vectorRequest()))->toBeTrue();
});

it('rejects a signature in a scheme it does not accept rather than passing it', function () {
    // The same signature, downgraded to a version it never signs for.
    $downgraded = 'v0,' . substr(Vector::SIGNATURE, 3);

    expect(verification()->verify(vectorRequest(['webhook-signature' => $downgraded])))->toBeFalse();
});

it('rejects a request presenting no signature at all', function () {
    expect(verification()->verify(vectorRequest(['webhook-signature' => ''])))->toBeFalse();
});

it('rejects a request presenting no timestamp at all', function () {
    expect(verification()->verify(vectorRequest(['webhook-timestamp' => ''])))->toBeFalse();
});

it('verifies a signature carried alongside ones it cannot read', function () {
    $header = 'v0,ignored ' . Vector::SIGNATURE;

    expect(verification()->verify(vectorRequest(['webhook-signature' => $header])))->toBeTrue();
});

it('rejects everything when the secret is empty', function () {
    expect(verification('')->verify(vectorRequest()))->toBeFalse();
});

it('accepts a secret without the whsec_ prefix', function () {
    expect(verification(substr(Vector::SECRET, 6))->verify(vectorRequest()))->toBeTrue();
});

it('refuses to fail silently on a secret it cannot decode', function () {
    // Standard Webhooks secrets are base64. A raw one would otherwise reject
    // every delivery forever, with nothing in the log to say why.
    expect(fn() => verification('whsec_a raw secret, not base64'))
        ->toThrow(InvalidProviderSecret::class, 'base64');
});

it('compares signatures in constant time', function () {
    // Timing cannot be asserted, so assert the only thing that guarantees it:
    // the comparison the class performs.
    $source = file_get_contents((new ReflectionClass(StandardWebhooks::class))->getFileName());

    expect($source)->toContain('hash_equals(')
        ->and($source)->not->toContain('=== $candidate')
        ->and($source)->not->toContain('== $candidate');
});

it('signs over the raw request body, never a re-encoded copy of it', function () {
    // Re-encoding this body changes its bytes but not its meaning.
    $body = '{"test":  2432232314}';

    $request = vectorRequest(['webhook-signature' => Vector::sign($body)], $body);

    expect(verification()->verify($request))->toBeTrue();
});

it("carries the readme's stripe recipe", function () {
    $secret = 'whsec_a_stripe_endpoint_secret';
    $body = '{"id":"evt_1","type":"invoice.paid"}';

    $signature = hash_hmac('sha256', Vector::TIMESTAMP . '.' . $body, $secret);
    $header = 't=' . Vector::TIMESTAMP . ',v1=' . $signature;

    $stripe = new StripeVerification($secret);

    expect($stripe->verify(signedRequest(['stripe-signature' => $header], $body)))->toBeTrue()
        // The same signature offered under the scheme Stripe has retired.
        ->and($stripe->verify(signedRequest(['stripe-signature' => 't=' . Vector::TIMESTAMP . ',v0=' . $signature], $body)))->toBeFalse()
        // Tampering with the body after signing.
        ->and($stripe->verify(signedRequest(['stripe-signature' => $header], $body . ' ')))->toBeFalse()
        // Signed with another endpoint's secret.
        ->and((new StripeVerification('whsec_someone_else'))->verify(signedRequest(['stripe-signature' => $header], $body)))->toBeFalse();

    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP + 301));

    expect($stripe->verify(signedRequest(['stripe-signature' => $header], $body)))->toBeFalse();
});
