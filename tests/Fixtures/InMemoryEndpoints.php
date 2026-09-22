<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests\Fixtures;

use Rooberthh\Switchboard\Contracts\Endpoints;
use Rooberthh\Switchboard\Outbox\EndpointData;

/**
 * An application's own endpoint storage, reduced to an array: the README's
 * custom storage recipe.
 */
final class InMemoryEndpoints implements Endpoints
{
    /** @var array<string, array{endpoint: EndpointData, event_types: list<string>, disabled: bool}> */
    private array $endpoints = [];

    /**
     * @param list<string> $eventTypes
     * @param EndpointData $endpoint
     */
    public function add(EndpointData $endpoint, array $eventTypes): void
    {
        $this->endpoints[$endpoint->id] = ['endpoint' => $endpoint, 'event_types' => $eventTypes, 'disabled' => false];
    }

    public function subscribedTo(string $eventType): iterable
    {
        foreach ($this->endpoints as $row) {
            if (! $row['disabled'] && in_array($eventType, $row['event_types'], true)) {
                yield $row['endpoint'];
            }
        }
    }

    public function find(string $id): ?EndpointData
    {
        $row = $this->endpoints[$id] ?? null;

        return $row === null || $row['disabled'] ? null : $row['endpoint'];
    }

    public function disable(string $id): void
    {
        if (isset($this->endpoints[$id])) {
            $this->endpoints[$id]['disabled'] = true;
        }
    }
}
