<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Rooberthh\Switchboard\Contracts\Endpoints;
use Rooberthh\Switchboard\Models\Delivery;
use Rooberthh\Switchboard\Models\OutboxMessage;

/**
 * Turn one committed message into one delivery per endpoint subscribed to its
 * event type, due now.
 *
 * The claim and the deliveries commit together: relayed_at is set by a
 * conditional update first, so of two overlapping relays only one creates the
 * deliveries, and a crash partway leaves the message unrelayed for the next
 * run rather than half relayed. The unique index on message and endpoint is
 * the second guard. A message nobody subscribes to is relayed with none — it
 * is still the record of what happened.
 *
 * @internal
 */
final class RelayOutboxMessageAction
{
    public function __construct(private readonly Endpoints $endpoints) {}

    /**
     * @return int The deliveries created; 0 when another relay got there first.
     * @param OutboxMessage $message
     */
    public function execute(OutboxMessage $message): int
    {
        return DB::transaction(function () use ($message): int {
            $now = Carbon::now();

            $claimed = OutboxMessage::query()
                ->whereKey($message->getKey())
                ->whereNull('relayed_at')
                ->update(['relayed_at' => $now]);

            if ($claimed === 0) {
                return 0;
            }

            $created = 0;

            foreach ($this->endpoints->subscribedTo($message->event_type) as $endpoint) {
                $created += Delivery::query()->insertOrIgnore([
                    'outbox_message_id' => $message->getKey(),
                    'endpoint_id' => $endpoint->id,
                    'url' => $endpoint->url,
                    'attempts' => 0,
                    'next_attempt_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $created;
        });
    }
}
