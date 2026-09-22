<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\SwitchboardServiceProvider;

use function Orchestra\Testbench\default_migration_path;

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
        // Endpoint secrets are encrypted at rest.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('s', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Testbench's own skeleton migrations, for the jobs table the
        // processing tests drive a real worker against.
        $this->loadMigrationsFrom(default_migration_path());

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
