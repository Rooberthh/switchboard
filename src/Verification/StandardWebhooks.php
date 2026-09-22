<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Verification;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Contracts\Verification;
use Rooberthh\Switchboard\Exceptions\InvalidProviderSecret;
use Rooberthh\Switchboard\Support\StandardWebhooksSignature;
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
        // Decoded up front, so an unusable secret throws where the provider
        // built this verification rather than rejecting every delivery
        // forever with nothing in the log to say why.
        $this->key = StandardWebhooksSignature::key($secret);
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
        return StandardWebhooksSignature::matches(
            (string) $request->header('webhook-signature', ''),
            (string) $request->header('webhook-id'),
            $timestamp,
            $request->getContent(),
            $this->key,
        );
    }
}
