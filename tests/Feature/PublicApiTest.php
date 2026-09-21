<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\File;
use Rooberthh\Switchboard\Models\InboxMessage;
use Illuminate\Support\Collection;
use Rooberthh\Switchboard\Contracts\Verification;
use Rooberthh\Switchboard\Contracts\WebhookProvider;
use Rooberthh\Switchboard\Inbox\WebhookProvider as BaseWebhookProvider;
use Rooberthh\Switchboard\Verification\StandardWebhooks;

/**
 * The compatibility policy, as a test. Every contract is public API; every
 * internal is final and marked @internal so nobody can couple to it.
 */
function packageClasses(): Collection
{
    return collect(File::allFiles(__DIR__ . '/../../src'))
        ->map(fn(SplFileInfo $file): string => 'Rooberthh\\Switchboard\\'
            . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
        ->filter(fn(string $class): bool => class_exists($class) || interface_exists($class))
        ->values();
}

/**
 * Internals that do not live under an internal namespace, and so have to be
 * named one by one. Keep the list short: it is easier to justify a namespace
 * than an exception.
 * @param string $class
 */
function isInternal(string $class): bool
{
    $named = [
        'Rooberthh\\Switchboard\\Inbox\\InboxMessages',
        'Rooberthh\\Switchboard\\Inbox\\Staleness',
    ];

    $namespaces = ['Actions', 'Console', 'Http', 'Jobs'];

    return in_array($class, $named, true)
        || collect($namespaces)->contains(
            fn(string $namespace): bool => str_starts_with($class, "Rooberthh\\Switchboard\\{$namespace}\\"),
        );
}

it('keeps every internal final and marked internal', function () {
    $shouldBeInternal = packageClasses()->filter(fn(string $class): bool => isInternal($class));

    expect($shouldBeInternal)->not->toBeEmpty();

    foreach ($shouldBeInternal as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue("{$class} must be final")
            ->and((string) $reflection->getDocComment())->toContain('@internal');
    }
});

it('marks nothing outside the internals as internal', function () {
    $public = packageClasses()->reject(fn(string $class): bool => isInternal($class));

    foreach ($public as $class) {
        expect((string) (new ReflectionClass($class))->getDocComment())->not->toContain('@internal');
    }
});

it('keeps every published contract to three methods or fewer, bar the provider', function () {
    $contracts = packageClasses()->filter(
        fn(string $class): bool => str_starts_with($class, 'Rooberthh\\Switchboard\\Contracts\\'),
    )->reject(
        // Deliberately large, so an integrator sees a whole integration in one
        // class. docs/adr/0005-a-provider-is-one-class.md
        fn(string $class): bool => $class === WebhookProvider::class,
    );

    expect($contracts)->not->toBeEmpty();

    foreach ($contracts as $contract) {
        // A contract small enough that it never has to grow, because adding a
        // method to a published interface breaks every implementor.
        expect(count((new ReflectionClass($contract))->getMethods()))->toBeLessThanOrEqual(3);
    }
});

it('ships an implementation to start from beside every contract', function () {
    expect((new ReflectionClass(BaseWebhookProvider::class))->isAbstract())->toBeTrue()
        ->and((new ReflectionClass(BaseWebhookProvider::class))->implementsInterface(WebhookProvider::class))->toBeTrue()
        ->and((new ReflectionClass(StandardWebhooks::class))->implementsInterface(Verification::class))->toBeTrue();
});

it('leaves the inbox message model open for an application to extend', function () {
    expect((new ReflectionClass(InboxMessage::class))->isFinal())->toBeFalse();
});

it('holds every event until the surrounding transaction commits', function () {
    // Nothing may act on a message that the transaction then rolled back, and
    // an event added later must not be able to forget that quietly.
    $events = packageClasses()->filter(
        fn(string $class): bool => str_starts_with($class, 'Rooberthh\\Switchboard\\Events\\'),
    );

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        expect((new ReflectionClass($event))->implementsInterface(ShouldDispatchAfterCommit::class))
            ->toBeTrue("{$event} must implement ShouldDispatchAfterCommit");
    }
});
