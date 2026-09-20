<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Exceptions;

use InvalidArgumentException;

final class UnknownProvider extends InvalidArgumentException
{
    public static function for(string $provider): self
    {
        return new self(
            "No Switchboard driver is registered for the provider [{$provider}]. "
            . "Register one with Switchboard::extend('{$provider}', ...) in a service provider's boot method.",
        );
    }
}
