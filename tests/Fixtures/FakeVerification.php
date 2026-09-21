<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests\Fixtures;

use Illuminate\Http\Request;
use Rooberthh\Switchboard\Contracts\Verification;

/**
 * A verification whose answer the test decides.
 */
final class FakeVerification implements Verification
{
    public function __construct(private readonly bool $verifies = true) {}

    public function verify(Request $request): bool
    {
        return $this->verifies;
    }
}
