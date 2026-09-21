<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests\Fixtures;

/**
 * A second provider, for the suites that must keep two apart.
 */
class OtherFakeProvider extends FakeProvider
{
    public static function name(): string
    {
        return 'other';
    }
}
