<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Drivers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Rooberthh\Switchboard\Exceptions\InvalidProviderSecret;

/**
 * A base class for providers that sign with Standard Webhooks: webhook-id,
 * webhook-timestamp and webhook-signature headers, "id.timestamp.body" signed
 * with a base64 secret, and the digest base64 encoded.
 *
 * This is a signing scheme, not a provider — it is the scheme Switchboard's
 * own outbox will sign with, made available inbound to anyone who wants it.
 * A subclass still declares its provider key and how to read its payload.
 *
 * @see https://www.standardwebhooks.com/
 *
 * Public API.
 */
abstract class StandardWebhooksDriver extends HmacDriver
{
    protected function signedPayload(Request $request): string
    {
        return $this->messageId($request)
            . '.' . (string) $request->header('webhook-timestamp')
            . '.' . $request->getContent();
    }

    /**
     * @return list<string>
     * @param Request $request
     */
    protected function signatures(Request $request): array
    {
        $presented = preg_split('/\s+/', trim((string) $request->header('webhook-signature', ''))) ?: [];

        $prefix = $this->version() . ',';

        return array_values(array_map(
            static fn(string $signature): string => substr($signature, strlen($prefix)),
            array_filter(
                $presented,
                static fn(string $signature): bool => str_starts_with($signature, $prefix),
            ),
        ));
    }

    protected function signedAt(Request $request): ?int
    {
        $timestamp = $request->header('webhook-timestamp');

        return is_numeric($timestamp) ? (int) $timestamp : null;
    }

    /**
     * The message id, which Standard Webhooks signs over and which doubles as
     * the event id a message is deduplicated on.
     * @param Request $request
     */
    protected function messageId(Request $request): string
    {
        return (string) $request->header('webhook-id');
    }

    protected function signingKey(): string
    {
        $secret = $this->secret();

        if ($secret === '') {
            return '';
        }

        $key = base64_decode(Str::after($secret, 'whsec_'), true);

        if ($key === false) {
            throw InvalidProviderSecret::notBase64($this->provider());
        }

        return $key;
    }

    protected function encode(string $digest): string
    {
        return base64_encode($digest);
    }

    /**
     * The signature version this driver accepts. Anything else presented
     * alongside it is ignored, never trusted.
     */
    protected function version(): string
    {
        return 'v1';
    }
}
