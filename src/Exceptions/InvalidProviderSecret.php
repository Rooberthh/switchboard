<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Exceptions;

use RuntimeException;

/**
 * The configured secret cannot be used, which is a misconfiguration rather
 * than a forgery. Thrown so it appears in the log instead of turning into an
 * endpoint that rejects every delivery forever with nothing to say why.
 */
final class InvalidProviderSecret extends RuntimeException
{
    public static function notBase64(string $provider): self
    {
        return new self(
            "The Switchboard secret configured for the provider [{$provider}] is not valid base64. "
            . 'Standard Webhooks secrets are base64, optionally prefixed with "whsec_".',
        );
    }
}
