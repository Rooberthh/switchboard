<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\SwitchboardServiceProvider;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Switchboard::flush();
    }

    protected function tearDown(): void
    {
        Switchboard::flush();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            SwitchboardServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
