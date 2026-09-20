<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Contracts;

use Illuminate\Http\Request;
use Rooberthh\Switchboard\Inbox\InboxMessageData;

/**
 * Teaches Switchboard how to read one provider.
 *
 * Switchboard never verifies a signature itself and never holds a secret: it
 * asks the driver whether a request is authentic, and then asks it what the
 * request means. See docs/adr/0001-no-shipped-provider-drivers.md.
 *
 * Public API. Adding a method here is a breaking change; anything new belongs
 * on {@see InboxMessageData} instead.
 */
interface Driver
{
    /**
     * Is this request authentic?
     *
     * Implementations compare signatures with hash_equals() and verify the raw
     * request body, never a re-encoded copy of it.
     * @param Request $request
     */
    public function verify(Request $request): bool;

    /**
     * What does this request mean?
     *
     * Only called for a request that verified.
     * @param Request $request
     */
    public function normalize(Request $request): InboxMessageData;
}
