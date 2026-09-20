<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Rooberthh\Switchboard\Models\InboxMessage;

/**
 * A message arrived, verified, and was persisted.
 *
 * Dispatched after commit: a listener must never act on a message that the
 * surrounding transaction then rolled back.
 *
 * An observation point, not a replacement seam — the work belongs in a
 * handler. Public API.
 */
final readonly class InboxMessageReceived implements ShouldDispatchAfterCommit
{
    public function __construct(public InboxMessage $message) {}
}
