<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Rooberthh\Switchboard\Models\InboxMessage;
use Throwable;

/**
 * A message has failed for good: its attempts are spent and failed_at is set.
 *
 * This is the event to alert on. An observation point, not a replacement seam.
 * Public API.
 */
final readonly class InboxMessageFailed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public InboxMessage $message,
        public Throwable $exception,
    ) {}
}
