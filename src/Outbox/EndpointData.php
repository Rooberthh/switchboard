<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Outbox;

use SensitiveParameter;

/**
 * What the outbox needs to know about one endpoint to deliver to it.
 *
 * Public API. Whatever storage an application puts behind the Endpoints
 * contract hands these out; a field added here as an optional constructor
 * argument is additive.
 */
final readonly class EndpointData
{
    /**
     * @param  string  $id  Stable for the endpoint's life: deliveries record it.
     * @param  string  $url  Where deliveries are sent.
     * @param  string  $secret  The Standard Webhooks secret deliveries are signed with, "whsec_" and base64.
     */
    public function __construct(
        public string $id,
        public string $url,
        #[SensitiveParameter]
        public string $secret,
    ) {}
}
