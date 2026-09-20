<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Contracts;

use Rooberthh\Switchboard\Models\InboxMessage;

/**
 * The application code that acts on an inbox message.
 *
 * A handler always runs on the queue, against a message that is already
 * stored, and never during the request that delivered it.
 *
 * Most applications extend {@see \Rooberthh\Switchboard\Inbox\Handler} rather
 * than implementing this directly.
 *
 * Public API. Adding a method here is a breaking change.
 */
interface Handler
{
    public function handle(InboxMessage $message): void;
}
