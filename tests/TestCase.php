<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Rooberthh\Switchboard\SwitchboardServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            SwitchboardServiceProvider::class,
        ];
    }
}
