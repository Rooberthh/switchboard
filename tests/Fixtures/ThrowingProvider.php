<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests\Fixtures;

/**
 * A provider whose every message fails in its handler.
 */
class ThrowingProvider extends FakeProvider
{
    public array $handlers = [
        'invoice.paid' => ThrowingHandler::class,
    ];
}
