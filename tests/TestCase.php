<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Rooberthh\Switchboard\Support\SsrfGuard;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\SwitchboardServiceProvider;

use function Orchestra\Testbench\default_migration_path;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * A globally routable address hosts resolve to in tests.
     */
    public const PUBLIC_ADDRESS = '93.184.215.14';

    protected function setUp(): void
    {
        parent::setUp();

        Switchboard::flush();

        // No test touches real DNS: every host resolves to one public address
        // unless a test says otherwise.
        $this->app->instance(SsrfGuard::class, new SsrfGuard(fn(string $host): array => [self::PUBLIC_ADDRESS]));
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
