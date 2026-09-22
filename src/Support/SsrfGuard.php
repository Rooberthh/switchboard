<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Support;

use Closure;
use Rooberthh\Switchboard\Exceptions\UnsafeEndpoint;

/**
 * Decide, once per attempt, where a delivery may connect — and pin it there.
 *
 * The host is resolved here, every address it resolves to must be globally
 * routable, and the address that was checked is the one the connection is
 * pinned to. Checking and then letting the HTTP client resolve again would
 * leave a gap a DNS rebinding attack walks through: public for the check,
 * private for the connection.
 *
 * A host on switchboard.outbox.allowed_hosts is delivered to wherever it
 * resolves, and is not pinned — that is the escape hatch for localhost and
 * genuinely internal receivers.
 *
 * @internal
 */
final class SsrfGuard
{
    /** @var Closure(string): list<string> */
    private readonly Closure $resolve;

    /**
     * @param  (Closure(string): list<string>)|null  $resolve  Host to addresses. Defaults to the system resolver.
     */
    public function __construct(?Closure $resolve = null)
    {
        $this->resolve = $resolve ?? self::systemResolver(...);
    }

    /**
     * @return array<string, mixed> Options for the HTTP client: the pinned address, and no redirects.
     * @param string $url
     *
     * @throws UnsafeEndpoint
     */
    public function options(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw UnsafeEndpoint::scheme($url);
        }

        $options = ['allow_redirects' => false];

        if (self::allowed($host)) {
            return $options;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve)($host);

        if ($addresses === []) {
            throw UnsafeEndpoint::unresolvable($host);
        }

        // Every address, not just the first: a host that resolves to one
        // public and one private address is refused, not half trusted.
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                throw UnsafeEndpoint::notPublic($host, $address);
            }
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $address = $addresses[0];
        $pinned = str_contains($address, ':') ? "[{$address}]" : $address;

        $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinned}"]];

        return $options;
    }

    private static function allowed(string $host): bool
    {
        /** @var list<string> $allowed */
        $allowed = (array) config('switchboard.outbox.allowed_hosts', []);

        return in_array($host, array_map(strtolower(...), $allowed), true);
    }

    /**
     * @return list<string>
     * @param string $host
     */
    private static function systemResolver(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
