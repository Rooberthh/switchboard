<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Verification;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rooberthh\Switchboard\Contracts\Verification;
use Rooberthh\Switchboard\Exceptions\InvalidProviderSecret;
use SensitiveParameter;

/**
 * Standard Webhooks: webhook-id, webhook-timestamp and webhook-signature
 * headers, "id.timestamp.body" signed with HMAC-SHA256 under a base64 secret,
 * and the digest base64 encoded.
 *
 * This is a signing scheme, not a provider — it is the scheme Switchboard's
 * own outbox will sign with, made available inbound to anyone who wants it.
 *
 * @see https://www.standardwebhooks.com/
 *
 * Public API.
 */
final class StandardWebhooks implements Verification
{
    /**
     * The only signature version accepted. Anything else presented alongside
     * it is ignored, never trusted.
     */
    private const VERSION = 'v1';

    private readonly string $key;

    private readonly int $tolerance;

    /**
     * @param  string  $secret  The provider's secret, base64 and optionally prefixed with "whsec_". An empty secret rejects every request.
     * @param  int|null  $tolerance  How many seconds either side of now a signed timestamp may be. Defaults to config('switchboard.inbox.tolerance').
     *
     * @throws InvalidProviderSecret when the secret is not valid base64
     */
    public function __construct(#[SensitiveParameter] string $secret, ?int $tolerance = null)
    {
        $this->key = self::decode($secret);
        $this->tolerance = $tolerance ?? (int) config('switchboard.inbox.tolerance', 300);
    }

    public function verify(Request $request): bool
    {
        if ($this->key === '') {
            return false;
        }

        $timestamp = $request->header('webhook-timestamp');

        if (! is_numeric($timestamp)) {
            return false;
        }

        // Symmetric: too old and too far in the future are both refused.
        if (abs(Carbon::now()->getTimestamp() - (int) $timestamp) > $this->tolerance) {
            return false;
        }

        // The raw body, never a decoded and re-encoded copy of it.
        $signed = (string) $request->header('webhook-id') . '.' . $timestamp . '.' . $request->getContent();

        $expected = base64_encode(hash_hmac('sha256', $signed, $this->key, true));

        $verified = false;

        foreach ($this->signatures($request) as $candidate) {
            // Deliberately not short-circuiting: every candidate costs the same.
            $verified = hash_equals($expected, $candidate) || $verified;
        }

        return $verified;
    }

    /**
     * The signatures presented in the accepted version, stripped of it. One
     * in any other version is dropped, so a downgrade can never pass.
     *
     * @return list<string>
     * @param Request $request
     */
    private function signatures(Request $request): array
    {
        $presented = preg_split('/\s+/', trim((string) $request->header('webhook-signature', ''))) ?: [];

        $prefix = self::VERSION . ',';

        $signatures = [];

        foreach ($presented as $signature) {
            if (str_starts_with($signature, $prefix)) {
                $signatures[] = substr($signature, strlen($prefix));
            }
        }

        return $signatures;
    }

    /**
     * Decoded up front, so an unusable secret throws where the provider built
     * this verification rather than rejecting every delivery forever with
     * nothing in the log to say why.
     *
     * @param string $secret
     */
    private static function decode(#[SensitiveParameter] string $secret): string
    {
        if ($secret === '') {
            return '';
        }

        $key = base64_decode(Str::after($secret, 'whsec_'), true);

        if ($key === false) {
            throw InvalidProviderSecret::notBase64();
        }

        return $key;
    }
}
