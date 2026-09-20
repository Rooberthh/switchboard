<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Contracts\Driver;
use Rooberthh\Switchboard\Inbox\InboxMessageData;

/**
 * A driver whose verification is controlled by the test, and which reads the
 * normalized fields straight off the JSON body.
 */
class FakeDriver implements Driver
{
    public function __construct(private readonly bool $verifies = true) {}

    public function verify(Request $request): bool
    {
        return $this->verifies;
    }

    public function normalize(Request $request): InboxMessageData
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        return new InboxMessageData(
            eventId: (string) $payload['id'],
            eventType: (string) $payload['type'],
            data: is_array($payload['data'] ?? null) ? $payload['data'] : [],
            subject: isset($payload['subject']) ? (string) $payload['subject'] : null,
            occurredAt: isset($payload['occurred_at']) ? Carbon::parse((string) $payload['occurred_at']) : null,
        );
    }
}
