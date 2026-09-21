<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Contracts\Verification;
use Rooberthh\Switchboard\Inbox\InboxMessageData;
use Rooberthh\Switchboard\Inbox\WebhookProvider;

/**
 * A provider that verifies everything and reads the message's fields straight
 * off the JSON body. Tests change one part by extending it anonymously.
 */
class FakeProvider extends WebhookProvider
{
    public array $handlers = [
        'invoice.paid' => RecordingHandler::class,
    ];

    public static function name(): string
    {
        return 'acme';
    }

    public function verification(): Verification
    {
        return new FakeVerification();
    }

    public function toInboxMessageData(Request $request): InboxMessageData
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        return new InboxMessageData(
            provider: static::name(),
            eventId: (string) $payload['id'],
            eventType: (string) $payload['type'],
            data: is_array($payload['data'] ?? null) ? $payload['data'] : [],
            subject: isset($payload['subject']) ? (string) $payload['subject'] : null,
            occurredAt: isset($payload['occurred_at']) ? Carbon::parse((string) $payload['occurred_at']) : null,
        );
    }
}
