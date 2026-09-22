<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Support;

use Illuminate\Support\Str;
use Rooberthh\Switchboard\Exceptions\InvalidProviderSecret;
use SensitiveParameter;

/**
 * Standard Webhooks signing, for both directions: the outbox signs with it and
 * the inbox's StandardWebhooks verification checks with it, so the two stay
 * compatible by construction (ADR-0002).
 *
 * "id.timestamp.body" is signed with HMAC-SHA256 under the secret's decoded
 * bytes, and the digest is base64 encoded. Only version v1 exists here.
 *
 * @see https://www.standardwebhooks.com/
 *
 * @internal
 */
final class StandardWebhooksSignature
{
    public const VERSION = 'v1';

    /**
     * The key a secret signs with: base64, optionally prefixed "whsec_". An
     * empty secret gives an empty key, which signs nothing a verifier accepts.
     *
     * @param string $secret
     *
     * @throws InvalidProviderSecret when the secret is not valid base64
     */
    public static function key(#[SensitiveParameter] string $secret): string
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

    /**
     * The base64 signature of one message, without its version prefix.
     *
     * @param string $id
     * @param int|string $timestamp
     * @param string $body The raw body, byte for byte — never a re-encoded copy.
     * @param string $key A decoded key, from key().
     */
    public static function sign(string $id, int|string $timestamp, string $body, #[SensitiveParameter] string $key): string
    {
        return base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    }

    /**
     * The webhook-signature header value for one message: "v1,<signature>".
     *
     * @param string $id
     * @param int $timestamp
     * @param string $body
     * @param string $key
     */
    public static function header(string $id, int $timestamp, string $body, #[SensitiveParameter] string $key): string
    {
        return self::VERSION . ',' . self::sign($id, $timestamp, $body, $key);
    }

    /**
     * Whether any v1 signature in a webhook-signature header matches. Any
     * other version is dropped, never trusted, so a downgrade cannot pass;
     * every candidate is compared in constant time, without short-circuiting.
     *
     * @param string $header
     * @param string $id
     * @param int|string $timestamp
     * @param string $body
     * @param string $key
     */
    public static function matches(string $header, string $id, int|string $timestamp, string $body, #[SensitiveParameter] string $key): bool
    {
        if ($key === '') {
            return false;
        }

        $expected = self::sign($id, $timestamp, $body, $key);
        $prefix = self::VERSION . ',';

        $verified = false;

        foreach (preg_split('/\s+/', trim($header)) ?: [] as $signature) {
            if (! str_starts_with($signature, $prefix)) {
                continue;
            }

            $verified = hash_equals($expected, substr($signature, strlen($prefix))) || $verified;
        }

        return $verified;
    }
}
