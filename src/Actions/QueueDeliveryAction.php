<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Actions;

use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Jobs\AttemptDelivery;
use Rooberthh\Switchboard\Models\Delivery;
use Throwable;

/**
 * Queue one due delivery, leasing it so the next relay run leaves it alone.
 *
 * The lease pushes its next attempt time forward before the job is queued.
 * If the job is lost, the lease runs out and a later relay queues it again —
 * which is what makes delivery at-least-once. If the queue refuses the job
 * outright, the lease is put back, so a queue that is part of an outage
 * leaves the delivery exactly as due as it was.
 *
 * @internal
 */
final class QueueDeliveryAction
{
    /**
     * @return bool Whether this call queued it; false when it was no longer due.
     * @param Delivery $delivery
     */
    public function execute(Delivery $delivery): bool
    {
        $due = $delivery->next_attempt_at;

        $leased = Delivery::query()
            ->whereKey($delivery->getKey())
            ->due()
            ->update(['next_attempt_at' => Carbon::now()->addSeconds(self::lease())]);

        if ($leased === 0) {
            return false;
        }

        try {
            AttemptDelivery::dispatch($delivery->getKey());
        } catch (Throwable $e) {
            Delivery::query()->whereKey($delivery->getKey())->update(['next_attempt_at' => $due]);

            throw $e;
        }

        return true;
    }

    /**
     * Long enough for a queued attempt to be picked up and finish, request
     * timeout included; after it, the relay presumes the job lost.
     */
    private static function lease(): int
    {
        return max(60, (int) config('switchboard.outbox.lease', 300));
    }
}
