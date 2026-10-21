<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Exceptions;

use RuntimeException;

/**
 * The configured secret cannot be used, which is a misconfiguration rather
 * than a forgery. Thrown so it appears in the log instead of turning into an
 * endpoint that rejects every delivery forever with nothing to say why.
 *
 * The outbox reports it rather than throwing it: an attempt at an endpoint
 * whose secret cannot sign is recorded and retried like any failed attempt.
 */
final class InvalidProviderSecret extends RuntimeException
{
    public static function empty(): self
    {
        return new self(
            'The Standard Webhooks secret is empty, so it would sign with no key at all. '
            . 'Standard Webhooks secrets are base64, optionally prefixed with "whsec_".',
        );
    }

    public static function notBase64(): self
    {
        return new self(
            'The Standard Webhooks secret is not valid base64. '
            . 'Standard Webhooks secrets are base64, optionally prefixed with "whsec_".',
        );
    }
}
