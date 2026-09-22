<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Exceptions\InvalidProviderSecret;
use Rooberthh\Switchboard\Support\StandardWebhooksSignature as Signature;
use Rooberthh\Switchboard\Tests\Fixtures\StandardWebhooksVector as Vector;
use Rooberthh\Switchboard\Verification\StandardWebhooks;

it('reproduces the standard webhooks reference vector', function () {
    $header = Signature::header(Vector::ID, Vector::TIMESTAMP, Vector::PAYLOAD, Signature::key(Vector::SECRET));

    expect($header)->toBe(Vector::SIGNATURE);
});

it('signs what the inbox verification accepts', function () {
    // The two directions share one implementation, so a Switchboard outbox
    // and a Switchboard inbox agree by construction.
    Carbon::setTestNow(Carbon::createFromTimestamp(1_700_000_000));

    $secret = 'whsec_' . base64_encode(random_bytes(32));
    $body = '{"type":"invoice.paid","timestamp":"2023-11-14T22:13:20.000000Z","data":{"amount":1000}}';

    $request = Request::create('/webhooks/acme', 'POST', server: [
        'HTTP_WEBHOOK_ID' => 'msg_1',
        'HTTP_WEBHOOK_TIMESTAMP' => '1700000000',
        'HTTP_WEBHOOK_SIGNATURE' => Signature::header('msg_1', 1_700_000_000, $body, Signature::key($secret)),
    ], content: $body);

    expect((new StandardWebhooks($secret))->verify($request))->toBeTrue()
        ->and((new StandardWebhooks('whsec_' . base64_encode('another secret')))->verify($request))->toBeFalse();

    Carbon::setTestNow();
});

it('matches a v1 signature among several and ignores any other version', function () {
    $key = Signature::key(Vector::SECRET);
    $signature = substr(Vector::SIGNATURE, 3);

    expect(Signature::matches('v0,nope ' . Vector::SIGNATURE, Vector::ID, Vector::TIMESTAMP, Vector::PAYLOAD, $key))->toBeTrue()
        ->and(Signature::matches('v0,' . $signature, Vector::ID, Vector::TIMESTAMP, Vector::PAYLOAD, $key))->toBeFalse()
        ->and(Signature::matches('', Vector::ID, Vector::TIMESTAMP, Vector::PAYLOAD, $key))->toBeFalse();
});

it('never matches with an empty key', function () {
    expect(Signature::matches(Vector::SIGNATURE, Vector::ID, Vector::TIMESTAMP, Vector::PAYLOAD, ''))->toBeFalse();
});

it('decodes a secret with or without its prefix, and refuses one that is not base64', function () {
    expect(Signature::key(Vector::SECRET))->toBe(Signature::key(substr(Vector::SECRET, 6)))
        ->and(Signature::key(''))->toBe('');

    Signature::key('whsec_not,base64!');
})->throws(InvalidProviderSecret::class);
