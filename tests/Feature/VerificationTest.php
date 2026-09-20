<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Contracts\Driver;
use Rooberthh\Switchboard\Inbox\InboxMessageData;
use Rooberthh\Switchboard\Models\InboxMessage;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Tests\Fixtures\AcmeStandardWebhooksDriver;
use Rooberthh\Switchboard\Tests\Fixtures\StandardWebhooksVector as Vector;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['switchboard.providers.acme.secret' => Vector::SECRET]);

    Carbon::setTestNow(Carbon::createFromTimestamp(Vector::TIMESTAMP));

    Switchboard::extend('acme', new AcmeStandardWebhooksDriver());
    Switchboard::route('acme');
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

it('works with a driver that ignores the base class entirely', function () {
    Switchboard::extend('acme', new class implements Driver {
        public function verify(Request $request): bool
        {
            // Whatever the application decides authentic means.
            return $request->header('x-shared-token') === 'let me in';
        }

        public function normalize(Request $request): InboxMessageData
        {
            return new InboxMessageData(eventId: 'evt_1', eventType: 'invoice.paid');
        }
    });

    post(['x-shared-token' => 'let me in'], '{}')->assertNoContent();
    post(['x-shared-token' => 'wrong'], '{}')->assertStatus(400);

    expect(InboxMessage::query()->count())->toBe(1);
});
