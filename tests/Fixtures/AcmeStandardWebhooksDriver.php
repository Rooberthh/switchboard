<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Drivers\StandardWebhooksDriver;
use Rooberthh\Switchboard\Inbox\InboxMessageData;

/**
 * What a driver costs an application when it signs with Standard Webhooks:
 * the provider key, and how to read the payload.
 */
class AcmeStandardWebhooksDriver extends StandardWebhooksDriver
{
    protected function provider(): string
    {
        return 'acme';
    }

    public function normalize(Request $request): InboxMessageData
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        return new InboxMessageData(
            provider: $this->provider(),
            eventId: (string) $request->header('webhook-id'),
            eventType: (string) ($payload['type'] ?? 'unknown'),
            data: is_array($payload['data'] ?? null) ? $payload['data'] : [],
            subject: isset($payload['subject']) ? (string) $payload['subject'] : null,
            occurredAt: Carbon::createFromTimestamp((int) $request->header('webhook-timestamp')),
        );
    }
}
