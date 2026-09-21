<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Contracts\Verification;
use SensitiveParameter;

/**
 * The README's Stripe recipe: a verification for a scheme Switchboard does not
 * ship. Kept identical to the README so the recipe is known to work.
 */
final class StripeVerification implements Verification
{
    public function __construct(
        #[SensitiveParameter]
        private readonly string $secret,
        private readonly int $tolerance = 300,
    ) {}

    public function verify(Request $request): bool
    {
        // Stripe-Signature: t=1614265330,v1=5257a8...,v0=an older scheme
        $parts = [];

        foreach (explode(',', (string) $request->header('Stripe-Signature')) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[$key][] = $value;
        }

        $timestamp = $parts['t'][0] ?? null;

        if ($this->secret === '' || ! is_numeric($timestamp)) {
            return false;
        }

        if (abs(Carbon::now()->getTimestamp() - (int) $timestamp) > $this->tolerance) {
            return false;
        }

        // Stripe signs "<timestamp>.<raw body>", hex encoded.
        $expected = hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $this->secret);

        // Only v1 is read, so a downgraded v0 signature is never trusted.
        foreach ($parts['v1'] ?? [] as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
