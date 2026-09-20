<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Rooberthh\Switchboard\Models\InboxMessage;
use Illuminate\Support\Collection;
use Rooberthh\Switchboard\Contracts\Driver;
use Rooberthh\Switchboard\Drivers\HmacDriver;
use Rooberthh\Switchboard\Inbox\Handler;

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

    $namespaces = ['Console', 'Http', 'Jobs'];

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

it('keeps every published contract to three methods or fewer', function () {
    $contracts = packageClasses()->filter(
        fn(string $class): bool => str_starts_with($class, 'Rooberthh\\Switchboard\\Contracts\\'),
    );

    expect($contracts)->not->toBeEmpty();

    foreach ($contracts as $contract) {
        // A contract small enough that it never has to grow, because adding a
        // method to a published interface breaks every implementor.
        expect(count((new ReflectionClass($contract))->getMethods()))->toBeLessThanOrEqual(3);
    }
});

it('ships an abstract base class beside every contract', function () {
    expect((new ReflectionClass(HmacDriver::class))->isAbstract())->toBeTrue()
        ->and((new ReflectionClass(HmacDriver::class))->implementsInterface(Driver::class))->toBeTrue()
        ->and((new ReflectionClass(Handler::class))->isAbstract())->toBeTrue()
        ->and((new ReflectionClass(Handler::class))->implementsInterface(Rooberthh\Switchboard\Contracts\Handler::class))->toBeTrue();
});

it('leaves the inbox message model open for an application to extend', function () {
    expect((new ReflectionClass(InboxMessage::class))->isFinal())->toBeFalse();
});
