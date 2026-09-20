<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Events;

use Rooberthh\Switchboard\Models\InboxMessage;

/**
 * A handler returned successfully and the message is processed.
 *
 * An observation point, not a replacement seam — the work belongs in a
 * handler. Public API.
 */
final readonly class InboxMessageProcessed
{
    public function __construct(public InboxMessage $message) {}
}
