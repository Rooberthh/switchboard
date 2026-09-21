<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Rooberthh\Switchboard\Exceptions\InvalidProvider;
use Rooberthh\Switchboard\Exceptions\UnknownProvider;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Tests\Fixtures\FakeProvider;
use Rooberthh\Switchboard\Tests\Fixtures\MarkerMiddleware;
use Rooberthh\Switchboard\Tests\Fixtures\OtherFakeProvider;

beforeEach(function () {
    // This suite is about the request, not about what happens after it.
    Queue::fake();
});

it('mounts a provider endpoint at a conventional path derived from its name', function () {
    Switchboard::provider(FakeProvider::class);

    $this->postJson('webhooks/acme', ['id' => 'evt_1', 'type' => 'invoice.paid'])
        ->assertNoContent();
});

it('accepts a path override at registration', function () {
    Switchboard::provider(FakeProvider::class, path: 'hooks/acme/inbound');

    $this->postJson('hooks/acme/inbound', ['id' => 'evt_1', 'type' => 'invoice.paid'])
        ->assertNoContent();

    $this->postJson('webhooks/acme', ['id' => 'evt_1', 'type' => 'invoice.paid'])
        ->assertNotFound();
});

it('takes the conventional path prefix from configuration', function () {
    config(['switchboard.inbox.path' => 'integrations/']);

    Switchboard::provider(FakeProvider::class);

    $this->postJson('integrations/acme', ['id' => 'evt_1', 'type' => 'invoice.paid'])
        ->assertNoContent();
});

it('returns the route, so middleware attaches the ordinary way', function () {
    Switchboard::provider(FakeProvider::class)->middleware(MarkerMiddleware::class);

    $this->postJson('webhooks/acme', ['id' => 'evt_1', 'type' => 'invoice.paid'])
        ->assertNoContent()
        ->assertHeader('X-Marker', 'ran');
});

it('only answers POST', function () {
    Switchboard::provider(FakeProvider::class);

    $this->getJson('webhooks/acme')->assertStatus(405);
});

it('names the route after the provider so applications can generate its url', function () {
    $route = Switchboard::provider(FakeProvider::class);

    expect($route->getName())->toBe('switchboard.inbox.acme');
});

it('keeps two providers on their own endpoints', function () {
    Switchboard::provider(FakeProvider::class);
    Switchboard::provider(OtherFakeProvider::class);

    $this->postJson('webhooks/other', ['id' => 'evt_1', 'type' => 'invoice.paid'])->assertNoContent();

    expect(Switchboard::providers())->toBe(['acme', 'other']);
});

it('builds nothing at registration, so a costly provider cannot break boot or caching', function () {
    $costly = new class extends FakeProvider {
        public static int $built = 0;

        public function __construct()
        {
            self::$built++;
        }
    };
    $costly::$built = 0;

    Switchboard::provider($costly::class);

    expect($costly::$built)->toBe(0);

    $this->postJson('webhooks/acme', ['id' => 'evt_1', 'type' => 'invoice.paid'])->assertNoContent();

    expect($costly::$built)->toBe(1);
});

it('builds a fresh provider for each resolution rather than holding one for the life of the worker', function () {
    Switchboard::provider(FakeProvider::class);

    expect(Switchboard::resolve('acme'))->not->toBe(Switchboard::resolve('acme'));
});

it('resolves a provider through the container, so an application can swap it', function () {
    Switchboard::provider(FakeProvider::class);

    $swapped = new class extends FakeProvider {};
    app()->instance(FakeProvider::class, $swapped);

    expect(Switchboard::resolve('acme'))->toBe($swapped);
});

it('refuses a second provider under a name already taken', function () {
    Switchboard::provider(FakeProvider::class);

    Switchboard::provider((new class extends FakeProvider {})::class);
})->throws(InvalidProvider::class, '[acme]');

it('refuses a class that is not a provider', function () {
    Switchboard::provider(stdClass::class);
})->throws(InvalidProvider::class, 'stdClass');

it('throws, naming it, when resolving a provider nobody registered', function () {
    Switchboard::resolve('acme');
})->throws(UnknownProvider::class, 'acme');

it('does not register the route when the class is refused', function () {
    try {
        Switchboard::provider(stdClass::class);
    } catch (InvalidProvider) {
        // Expected: registration is what fails, not the first request.
    }

    expect(Route::getRoutes()->getByName('switchboard.inbox.acme'))->toBeNull();
});

it('ships no facade', function () {
    expect(class_exists('Rooberthh\\Switchboard\\Facades\\Switchboard'))->toBeFalse();
});
