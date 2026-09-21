<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Rooberthh\Switchboard\Contracts\Verification;
use Rooberthh\Switchboard\Exceptions\InvalidProviderSecret;
use Rooberthh\Switchboard\Models\InboxMessage;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Tests\Fixtures\AcmeStandardWebhooksProvider;
use Rooberthh\Switchboard\Tests\Fixtures\StandardWebhooksVector as Vector;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    // These suites are about the request, not about what happens after it.
    Queue::fake();

    config(['switchboard.providers.acme.secret' => Vector::SECRET]);

    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP));

    Switchboard::provider(AcmeStandardWebhooksProvider::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

function post(array $headers, string $body = Vector::PAYLOAD): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', 'webhooks/acme', server: $server, content: $body);
}

$body = '{"type":"invoice.paid","data":{"amount":1000}}';

it('accepts a correctly signed request', function () use ($body) {
    post(Vector::headers($body), $body)->assertNoContent();

    expect(InboxMessage::query()->sole()->event_id)->toBe(Vector::ID);
});

it('rejects a tampered body', function () use ($body) {
    post(Vector::headers($body), '{"type":"invoice.paid","data":{"amount":100000}}')->assertStatus(400);

    expect(InboxMessage::query()->count())->toBe(0);
});

it('rejects a request signed with the wrong secret', function () use ($body) {
    $headers = Vector::headers($body, secret: 'whsec_' . base64_encode('an attacker guess'));

    post($headers, $body)->assertStatus(400);

    expect(InboxMessage::query()->count())->toBe(0);
});

it('rejects a stale timestamp', function () use ($body) {
    $headers = Vector::headers($body, timestamp: Vector::TIMESTAMP - 301);

    post($headers, $body)->assertStatus(400);

    expect(InboxMessage::query()->count())->toBe(0);
});

it('rejects a timestamp too far in the future', function () use ($body) {
    $headers = Vector::headers($body, timestamp: Vector::TIMESTAMP + 301);

    post($headers, $body)->assertStatus(400);

    expect(InboxMessage::query()->count())->toBe(0);
});

it('rejects a downgraded signature scheme', function () use ($body) {
    $headers = Vector::headers($body);
    $headers['webhook-signature'] = 'v0,' . substr($headers['webhook-signature'], 3);

    post($headers, $body)->assertStatus(400);

    expect(InboxMessage::query()->count())->toBe(0);
});

it('rejects an unsigned request', function () use ($body) {
    post(['webhook-id' => Vector::ID, 'webhook-timestamp' => (string) Vector::TIMESTAMP], $body)->assertStatus(400);

    expect(InboxMessage::query()->count())->toBe(0);
});

it('tells an attacker nothing about which part of the forgery was wrong', function () use ($body) {
    $tampered = post(Vector::headers($body), '{"type":"invoice.paid","data":{"amount":100000}}');
    $wrongSecret = post(Vector::headers($body, secret: 'whsec_' . base64_encode('an attacker guess')), $body);
    $stale = post(Vector::headers($body, timestamp: Vector::TIMESTAMP - 301), $body);
    $unsigned = post([], $body);

    $responses = collect([$tampered, $wrongSecret, $stale, $unsigned])
        ->map(fn($response) => [
            'status' => $response->getStatusCode(),
            'content' => $response->getContent(),
            'headers' => $response->headers->get('Content-Type'),
        ])
        ->unique()
        ->values();

    expect($responses)->toHaveCount(1)
        ->and($responses->first()['status'])->toBe(400)
        ->and($responses->first()['content'])->toBe('');
});

it('verifies with whatever verification the provider returns', function () {
    Switchboard::flush();

    Switchboard::provider((new class extends AcmeStandardWebhooksProvider {
        public function verification(): Verification
        {
            return new class implements Verification {
                public function verify(Request $request): bool
                {
                    // Whatever the application decides authentic means.
                    return $request->header('x-shared-token') === 'let me in';
                }
            };
        }
    })::class);

    post(['x-shared-token' => 'let me in', 'webhook-id' => 'evt_1'], '{}')->assertNoContent();
    post(['x-shared-token' => 'wrong', 'webhook-id' => 'evt_2'], '{}')->assertStatus(400);

    expect(InboxMessage::query()->count())->toBe(1);
});

it('answers with the same 400, and reports it, when the secret is unusable', function () {
    Exceptions::fake();

    config(['switchboard.providers.acme.secret' => 'whsec_a raw secret, not base64']);

    post(Vector::headers('{}'), '{}')->assertStatus(400);

    Exceptions::assertReported(InvalidProviderSecret::class);

    expect(InboxMessage::query()->count())->toBe(0);
});
