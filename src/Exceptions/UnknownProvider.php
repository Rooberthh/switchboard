<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Exceptions;

use InvalidArgumentException;

final class UnknownProvider extends InvalidArgumentException
{
    public static function for(string $provider): self
    {
        return new self(
            "No Switchboard provider is registered under the name [{$provider}]. "
            . "Register its class with Switchboard::provider(...) in a service provider's boot method.",
        );
    }
}
