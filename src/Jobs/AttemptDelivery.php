<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Rooberthh\Switchboard\Actions\AttemptDeliveryAction;
use Rooberthh\Switchboard\Models\Delivery;

/**
 * One attempt at one delivery.
 *
 * Tried once: the retry schedule lives on the delivery, not on the queue, so
 * a wait of hours survives a queue outage and works on every driver
 * (ADR-0007). An attempt that dies unexpectedly is recovered by the relay
 * when its lease runs out.
 *
 * @internal
 */
final class AttemptDelivery implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $deliveryId)
    {
        $this->onConnection(config('switchboard.queue.connection'));
        $this->onQueue(config('switchboard.queue.name'));
    }

    public function handle(AttemptDeliveryAction $attempt): void
    {
        $delivery = Delivery::query()->find($this->deliveryId);

        if ($delivery === null || ! $delivery->isPending()) {
            return;
        }

        $attempt->execute($delivery);
    }
}
