<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Rooberthh\Switchboard\Models\Delivery;

/**
 * An endpoint answered a delivery with 2xx.
 *
 * Dispatched after commit. An observation point, not a replacement seam.
 * Public API.
 */
final readonly class OutboxDeliverySucceeded implements ShouldDispatchAfterCommit
{
    public function __construct(public Delivery $delivery) {}
}
