<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Exceptions;

use RuntimeException;

final class UnknownHandler extends RuntimeException
{
    public static function for(string $provider): self
    {
        return new self(
            "No Switchboard handler is registered for the provider [{$provider}]. "
            . "Register one with Switchboard::handledBy('{$provider}', ...) in a service provider's boot method.",
        );
    }
}
