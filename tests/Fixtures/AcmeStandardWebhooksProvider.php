<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests\Fixtures;

use Illuminate\Http\Request;
use Rooberthh\Switchboard\Contracts\Verification;
use Rooberthh\Switchboard\Inbox\InboxMessageData;
use Rooberthh\Switchboard\Inbox\WebhookProvider;
use Rooberthh\Switchboard\Verification\StandardWebhooks;

/**
 * The README quickstart's provider.
 */
class AcmeStandardWebhooksProvider extends WebhookProvider
{
    public static function name(): string
    {
        return 'acme';
    }

    public function verification(): Verification
    {
        return new StandardWebhooks($this->secret());
    }

    public function toInboxMessage(Request $request): InboxMessageData
    {
        $payload = $request->json()->all();

        return new InboxMessageData(
            provider: static::name(),
            eventId: (string) $request->header('webhook-id'),
            eventType: (string) ($payload['type'] ?? 'unknown'),
            data: (array) ($payload['data'] ?? []),
        );
    }
}
