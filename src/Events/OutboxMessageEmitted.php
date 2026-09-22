<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Rooberthh\Switchboard\Models\OutboxMessage;

/**
 * A message was emitted and has committed.
 *
 * Dispatched after commit: a listener must never react to a message that the
 * surrounding transaction then rolled back.
 *
 * An observation point, not a replacement seam. Public API.
 */
final readonly class OutboxMessageEmitted implements ShouldDispatchAfterCommit
{
    public function __construct(public OutboxMessage $message) {}
}
