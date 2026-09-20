<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests\Fixtures;

use Illuminate\Http\Request;
use Rooberthh\Switchboard\Drivers\HmacDriver;
use Rooberthh\Switchboard\Inbox\InboxMessageData;

/**
 * A provider that signs the body alone, hex encoded, with its timestamp in a
 * header of its own — the shape the HMAC base class has to cover beyond
 * Standard Webhooks.
 */
class AcmeHexDriver extends HmacDriver
{
    protected function provider(): string
    {
        return 'acme';
    }

    protected function signedPayload(Request $request): string
    {
        return $request->getContent();
    }

    protected function signatures(Request $request): array
    {
        $header = (string) $request->header('x-acme-signature', '');

        return $header === '' ? [] : [$header];
    }

    protected function signedAt(Request $request): ?int
    {
        $timestamp = $request->header('x-acme-timestamp');

        return is_numeric($timestamp) ? (int) $timestamp : null;
    }

    public function normalize(Request $request): InboxMessageData
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        return new InboxMessageData(
            eventId: (string) $payload['id'],
            eventType: (string) $payload['type'],
        );
    }
}
