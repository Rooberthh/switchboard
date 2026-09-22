<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Exceptions;

use RuntimeException;

/**
 * A delivery refused before it was sent, because its URL does not lead to
 * the public internet. Endpoint URLs are usually supplied by someone other
 * than the application, so this is the line between a webhook and a request
 * into the application's own network.
 */
final class UnsafeEndpoint extends RuntimeException
{
    public static function scheme(string $url): self
    {
        return new self("Refused to deliver to [{$url}]: only http and https URLs are delivered to.");
    }

    public static function unresolvable(string $host): self
    {
        return new self("Refused to deliver to [{$host}]: it did not resolve to any address.");
    }

    public static function notPublic(string $host, string $address): self
    {
        return new self(
            "Refused to deliver to [{$host}]: it resolves to [{$address}], which is not a public address. "
            . 'Add the host to switchboard.outbox.allowed_hosts if it is meant to receive webhooks.',
        );
    }
}
