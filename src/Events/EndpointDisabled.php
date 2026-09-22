<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Rooberthh\Switchboard\Outbox\EndpointData;

/**
 * An endpoint's receiver answered 410 Gone, so the endpoint is disabled and
 * receives nothing further.
 *
 * Dispatched after commit. The place to tell the endpoint's owner. Public API.
 */
final readonly class EndpointDisabled implements ShouldDispatchAfterCommit
{
    public function __construct(public EndpointData $endpoint) {}
}
