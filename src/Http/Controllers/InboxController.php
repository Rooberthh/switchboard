<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * @internal
 */
final class InboxController
{
    public function __invoke(Request $request, string $provider): Response
    {
        return response()->noContent();
    }
}
