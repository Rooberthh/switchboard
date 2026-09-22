<?php

declare(strict_types=1);

use Rooberthh\Switchboard\Exceptions\UnsafeEndpoint;
use Rooberthh\Switchboard\Support\SsrfGuard;

function guardResolving(string ...$addresses): SsrfGuard
{
    return new SsrfGuard(fn(string $host): array => array_values($addresses));
}

it('pins a public host to the address it checked, and never follows redirects', function () {
    $options = guardResolving('93.184.215.14')->options('https://hooks.acme.test/switchboard');

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['hooks.acme.test:443:93.184.215.14']);
});

it('pins the port the url names', function () {
    $options = guardResolving('93.184.215.14')->options('http://hooks.acme.test:8080/in');

    expect($options['curl'][CURLOPT_RESOLVE])->toBe(['hooks.acme.test:8080:93.184.215.14']);
});

it('pins an ipv6 address in brackets', function () {
    $options = guardResolving('2606:2800:21f:cb07:6820:80da:af6b:8b2c')->options('https://hooks.acme.test');

    expect($options['curl'][CURLOPT_RESOLVE])->toBe(['hooks.acme.test:443:[2606:2800:21f:cb07:6820:80da:af6b:8b2c]']);
});

it('refuses a host that resolves to a non-public address', function (string $address) {
    guardResolving($address)->options('https://hooks.acme.test');
})->with([
    'private 10/8' => '10.0.0.5',
    'private 172.16/12' => '172.16.4.2',
    'private 192.168/16' => '192.168.1.10',
    'loopback' => '127.0.0.1',
    'link-local and cloud metadata' => '169.254.169.254',
    'unspecified' => '0.0.0.0',
    'carrier-grade nat' => '100.64.0.1',
    'documentation' => '203.0.113.7',
    'ipv6 loopback' => '::1',
    'ipv6 unique local' => 'fd12:3456:789a::1',
    'ipv6 link-local' => 'fe80::1',
    'ipv4-mapped ipv6 loopback' => '::ffff:127.0.0.1',
])->throws(UnsafeEndpoint::class);

it('refuses an ip literal that is not public, without resolving anything', function (string $url) {
    $guard = new SsrfGuard(fn(string $host): array => throw new LogicException('resolved an ip literal'));

    $guard->options($url);
})->with([
    'http://127.0.0.1/hook',
    'http://169.254.169.254/latest/meta-data',
    'http://[::1]:8080/hook',
    'http://10.1.2.3/hook',
])->throws(UnsafeEndpoint::class);

it('refuses a host with one public and one private address, rather than half trusting it', function () {
    guardResolving('93.184.215.14', '10.0.0.5')->options('https://hooks.acme.test');
})->throws(UnsafeEndpoint::class, '10.0.0.5');

it('refuses a host that resolves to nothing', function () {
    guardResolving()->options('https://nowhere.acme.test');
})->throws(UnsafeEndpoint::class);

it('refuses anything but http and https', function (string $url) {
    guardResolving('93.184.215.14')->options($url);
})->with(['ftp://hooks.acme.test', 'file:///etc/passwd', 'gopher://hooks.acme.test', 'hooks.acme.test/no-scheme'])
    ->throws(UnsafeEndpoint::class);

it('resolves once per check, so a rebinding answer cannot reach the connection', function () {
    $answers = ['93.184.215.14', '127.0.0.1'];
    $lookups = 0;

    $guard = new SsrfGuard(function (string $host) use (&$answers, &$lookups): array {
        $lookups++;

        return [array_shift($answers)];
    });

    $options = $guard->options('https://rebind.acme.test');

    // One lookup, and the connection is pinned to its answer — the second,
    // private answer is never consulted.
    expect($lookups)->toBe(1)
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['rebind.acme.test:443:93.184.215.14']);
});

it('delivers to an allowed host wherever it resolves, unpinned', function () {
    config(['switchboard.outbox.allowed_hosts' => ['LocalHost']]);

    $guard = new SsrfGuard(fn(string $host): array => throw new LogicException('an allowed host is not resolved'));

    expect($guard->options('http://localhost:8000/hook'))->toBe(['allow_redirects' => false]);
});

it('uses the system resolver by default', function () {
    // An ip literal needs no DNS, so this runs offline.
    $options = (new SsrfGuard())->options('https://93.184.215.14/hook');

    expect($options['curl'][CURLOPT_RESOLVE])->toBe(['93.184.215.14:443:93.184.215.14']);
});
