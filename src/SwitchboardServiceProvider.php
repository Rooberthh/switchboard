<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard;

use Illuminate\Support\ServiceProvider;
use Rooberthh\Switchboard\Contracts\Endpoints;
use Rooberthh\Switchboard\Outbox\DatabaseEndpoints;
use Rooberthh\Switchboard\Console\MakeWebhookProviderCommand;
use Rooberthh\Switchboard\Console\OutboxRelayCommand;
use Rooberthh\Switchboard\Console\OutboxReplayCommand;
use Rooberthh\Switchboard\Console\RelayCommand;
use Rooberthh\Switchboard\Console\ReplayCommand;

class SwitchboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/switchboard.php', 'switchboard');

        // bindIf, so an application's own binding wins regardless of order.
        $this->app->bindIf(Endpoints::class, DatabaseEndpoints::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeWebhookProviderCommand::class,
                OutboxRelayCommand::class,
                OutboxReplayCommand::class,
                RelayCommand::class,
                ReplayCommand::class,
            ]);

            $this->publishes(
                [
                    __DIR__ . '/../config/switchboard.php' => config_path('switchboard.php'),
                ],
                'switchboard-config',
            );

            $this->publishesMigrations(
                [
                    __DIR__ . '/../database/migrations' => $this->app->databasePath('migrations'),
                ],
                'switchboard-migrations',
            );
        }
    }
}
