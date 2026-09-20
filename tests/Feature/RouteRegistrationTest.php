<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Rooberthh\Switchboard\Exceptions\UnknownProvider;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Tests\Fixtures\FakeDriver;
use Rooberthh\Switchboard\Tests\Fixtures\MarkerMiddleware;

it('mounts a provider endpoint at a conventional path derived from the provider key', function () {
    Switchboard::extend('acme', new FakeDriver());
    Switchboard::route('acme');

    $this->postJson('webhooks/acme', ['id' => 'evt_1', 'type' => 'invoice.paid'])
        ->assertNoContent();
});

it('accepts a path override at registration', function () {
    Switchboard::extend('acme', new FakeDriver());
    Switchboard::route('acme', path: 'hooks/acme/inbound');

    $this->postJson('hooks/acme/inbound', ['id' => 'evt_1', 'type' => 'invoice.paid'])
        ->assertNoContent();

    $this->postJson('webhooks/acme', ['id' => 'evt_1', 'type' => 'invoice.paid'])
        ->assertNotFound();
});

it('runs middleware attached at registration', function () {
    Switchboard::extend('acme', new FakeDriver());
    Switchboard::route('acme', middleware: [MarkerMiddleware::class]);

    $this->postJson('webhooks/acme', ['id' => 'evt_1', 'type' => 'invoice.paid'])
        ->assertNoContent()
        ->assertHeader('X-Marker', 'ran');
});

it('only answers POST', function () {
    Switchboard::extend('acme', new FakeDriver());
    Switchboard::route('acme');

    $this->getJson('webhooks/acme')->assertStatus(405);
});

it('resolves a driver registered as a class name through the container', function () {
    Switchboard::extend('acme', FakeDriver::class);

    expect(Switchboard::driver('acme'))->toBeInstanceOf(FakeDriver::class);
});

it('resolves a driver registered as a closure', function () {
    Switchboard::extend('acme', fn() => new FakeDriver());

    expect(Switchboard::driver('acme'))->toBeInstanceOf(FakeDriver::class);
});

it('throws when a route is registered for a provider with no driver', function () {
    Switchboard::route('acme');
})->throws(UnknownProvider::class, 'acme');

it('does not register the route when the provider is unknown', function () {
    try {
        Switchboard::route('acme');
    } catch (UnknownProvider) {
        // Expected: registration is what fails, not the first request.
    }

    expect(Route::getRoutes()->getByName('switchboard.inbox.acme'))->toBeNull();
});

it('names the route after the provider so applications can generate its url', function () {
    Switchboard::extend('acme', new FakeDriver());
    $route = Switchboard::route('acme');

    expect($route->getName())->toBe('switchboard.inbox.acme');
});

it('ships no facade', function () {
    expect(class_exists('Rooberthh\\Switchboard\\Facades\\Switchboard'))->toBeFalse();
});
