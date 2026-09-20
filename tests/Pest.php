<?php

declare(strict_types=1);

use Rooberthh\Switchboard\Models\InboxMessage;
use Rooberthh\Switchboard\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * A stored inbox message, unprocessed unless the test says otherwise.
 *
 * @param  array<string, mixed>  $attributes
 */
function inboxMessage(array $attributes = []): InboxMessage
{
    return InboxMessage::query()->create([
        'provider' => 'acme',
        'event_id' => 'evt_1',
        'event_type' => 'invoice.paid',
        'data' => ['amount' => 1000],
        'occurred_at' => now(),
        ...$attributes,
    ]);
}
