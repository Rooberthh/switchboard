<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Actions;

use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Exceptions\IllegalTransition;
use Rooberthh\Switchboard\Models\Delivery;

/**
 * Return one failed delivery to pending, due now, for the relay to send.
 *
 * Only a failed delivery is eligible: sending a delivered one again would
 * repeat what the receiver already has. It starts the schedule over, and it
 * sends the same stored body to the same snapshotted URL — replay is re-run
 * of exactly what was sent.
 *
 * @internal
 */
final class ReplayDeliveryAction
{
    /**
     * @throws IllegalTransition when the delivery has not failed
     * @param Delivery $delivery
     */
    public function execute(Delivery $delivery): void
    {
        if (! $delivery->isFailed()) {
            throw IllegalTransition::deliveryNotFailed($delivery);
        }

        $delivery->forceFill([
            'failed_at' => null,
            'attempts' => 0,
            'next_attempt_at' => Carbon::now(),
        ])->save();
    }
}
