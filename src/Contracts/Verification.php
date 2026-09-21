<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Contracts;

use Illuminate\Http\Request;
use Rooberthh\Switchboard\Verification\StandardWebhooks;

/**
 * A signature scheme: whether a request is authentic.
 *
 * A verification holds the scheme and whatever secret it is handed, never
 * where that secret lives. Implementations compare signatures with
 * hash_equals() and verify the raw request body, never a re-encoded copy.
 *
 * Switchboard ships {@see StandardWebhooks};
 * any other scheme is a class implementing this.
 *
 * Public API. Adding a method here is a breaking change.
 */
interface Verification
{
    /**
     * @param Request $request
     */
    public function verify(Request $request): bool;
}
