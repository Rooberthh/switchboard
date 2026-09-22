<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Contracts;

use Rooberthh\Switchboard\Outbox\EndpointData;

/**
 * Where the outbox's endpoints come from.
 *
 * Switchboard binds a default backed by its own endpoints table. Bind your own
 * implementation to keep endpoints in your existing storage — a webhooks table
 * of your own, a per-tenant setup — and the outbox works the same.
 *
 * Public API. Adding a method here is a breaking change.
 */
interface Endpoints
{
    /**
     * The active endpoints subscribed to exactly this event type.
     *
     * @return iterable<EndpointData>
     * @param string $eventType
     */
    public function subscribedTo(string $eventType): iterable;

    /**
     * One active endpoint, or null when it no longer exists or is disabled.
     *
     * @param string $id
     */
    public function find(string $id): ?EndpointData;

    /**
     * Stop sending to an endpoint: its receiver answered 410 Gone.
     *
     * @param string $id
     */
    public function disable(string $id): void;
}
