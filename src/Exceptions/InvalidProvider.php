<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Exceptions;

use InvalidArgumentException;
use Rooberthh\Switchboard\Contracts\WebhookProvider;

/**
 * A provider that cannot be registered. Thrown at registration, from a
 * service provider's boot method, rather than on the first delivery.
 */
final class InvalidProvider extends InvalidArgumentException
{
    public static function notAProvider(string $class): self
    {
        return new self(
            "[{$class}] is not a Switchboard provider. It must implement " . WebhookProvider::class
            . ', most simply by extending ' . \Rooberthh\Switchboard\Inbox\WebhookProvider::class . '.',
        );
    }

    public static function alreadyRegistered(string $name, string $class, string $registered): self
    {
        return new self(
            "Cannot register [{$class}] as the Switchboard provider [{$name}]: [{$registered}] is already registered under that name. "
            . 'A name is what stored messages are found by, so each provider needs its own.',
        );
    }
}
