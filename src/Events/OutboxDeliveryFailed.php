<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Rooberthh\Switchboard\Models\Delivery;

/**
 * A delivery has failed for good: its attempts are spent, or its endpoint is
 * gone. The endpoint itself keeps receiving new messages.
 *
 * Dispatched after commit. An observation point — the place to tell the
 * endpoint's owner. Public API.
 */
final readonly class OutboxDeliveryFailed implements ShouldDispatchAfterCommit
{
    public function __construct(public Delivery $delivery) {}
}
