<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Outbox;

use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Contracts\Endpoints;
use Rooberthh\Switchboard\Models\Endpoint;

/**
 * The default Endpoints: Switchboard's own endpoints table.
 *
 * @internal
 */
final class DatabaseEndpoints implements Endpoints
{
    public function subscribedTo(string $eventType): iterable
    {
        foreach (Endpoint::query()->subscribedTo($eventType)->lazyById() as $endpoint) {
            yield $endpoint->toEndpointData();
        }
    }

    public function find(string $id): ?EndpointData
    {
        return Endpoint::query()->active()->find($id)?->toEndpointData();
    }

    public function disable(string $id): void
    {
        Endpoint::query()->whereKey($id)->active()->update(['disabled_at' => Carbon::now()]);
    }
}
