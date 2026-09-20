<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard;

use Illuminate\Support\ServiceProvider;

class SwitchboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/switchboard.php', 'switchboard');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
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
