<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Rooberthh\Switchboard\SwitchboardServiceProvider;

it('merges the package config', function () {
    expect(config('switchboard.tables.inbox_messages'))->toBe('switchboard_inbox_messages')
        ->and(config('switchboard.queue'))->toBe(['connection' => null, 'name' => null]);
});

it('registers the config publish tag', function () {
    $paths = ServiceProvider::pathsToPublish(SwitchboardServiceProvider::class, 'switchboard-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toBe(config_path('switchboard.php'));
});

it('registers the migrations publish tag', function () {
    $paths = ServiceProvider::pathsToPublish(SwitchboardServiceProvider::class, 'switchboard-migrations');

    expect($paths)->toHaveCount(1);
});
