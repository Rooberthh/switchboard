<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Drivers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Contracts\Driver;

/**
 * A base class for drivers whose provider signs with an HMAC, which is nearly
 * all of them.
 *
 * It owns the parts that are the same everywhere and easy to get subtly wrong:
 * constant-time comparison, the symmetric timestamp window, and a conventional
 * place to read a secret from. A subclass declares the provider-specific parts
 * — which header carries the signature, what string is signed, and how it is
 * encoded.
 *
 * Ignoring this class is supported: a driver may implement {@see Driver}
 * directly, at which point the application has taken over verification
 * completely. See docs/adr/0001-no-shipped-provider-drivers.md.
 *
 * Public API.
 */
abstract class HmacDriver implements Driver
{
    /**
     * The provider key, which is where the conventional secret is read from.
     */
    abstract protected function provider(): string;

    /**
     * The exact string the provider signed.
     *
     * Build it from the raw request body — $request->getContent() — and never
     * from a decoded and re-encoded copy of it.
     * @param Request $request
     */
    abstract protected function signedPayload(Request $request): string;

    /**
     * The signatures the request presents, in the scheme this driver accepts,
     * decoded no further than the encoding the signature is compared in.
     *
     * Return an empty array when the request carries none the driver can read:
     * a signature in a scheme this driver does not accept must not be skipped
     * into a pass.
     *
     * @return list<string>
     * @param Request $request
     */
    abstract protected function signatures(Request $request): array;

    /**
     * When the provider says it signed the request, in Unix seconds, or null
     * when the request carries no timestamp this driver can read.
     * @param Request $request
     */
    abstract protected function signedAt(Request $request): ?int;

    final public function verify(Request $request): bool
    {
        $key = $this->signingKey();

        if ($key === '') {
            return false;
        }

        $signedAt = $this->signedAt($request);

        if ($signedAt === null) {
            return false;
        }

        if (abs(Carbon::now()->getTimestamp() - $signedAt) > $this->tolerance()) {
            return false;
        }

        $expected = $this->sign($this->signedPayload($request), $key);

        $verified = false;

        foreach ($this->signatures($request) as $candidate) {
            // Deliberately not short-circuiting: every candidate costs the same.
            $verified = hash_equals($expected, $candidate) || $verified;
        }

        return $verified;
    }

    /**
     * Where the secret comes from. Override this to read it anywhere else —
     * a per-tenant column, a secrets manager — without touching verification.
     */
    protected function secret(): string
    {
        return (string) config("switchboard.providers.{$this->provider()}.secret", '');
    }

    /**
     * The key the HMAC is computed with, derived from the secret. Override
     * when a provider encodes its secret, as Standard Webhooks does.
     */
    protected function signingKey(): string
    {
        return $this->secret();
    }

    /**
     * How many seconds either side of now a signed timestamp may be.
     */
    protected function tolerance(): int
    {
        return (int) config('switchboard.inbox.tolerance', 300);
    }

    protected function algorithm(): string
    {
        return 'sha256';
    }

    /**
     * How the provider encodes the digest. Hex by default; override for base64.
     * @param string $digest
     */
    protected function encode(string $digest): string
    {
        return bin2hex($digest);
    }

    final protected function sign(string $payload, string $key): string
    {
        return $this->encode(hash_hmac($this->algorithm(), $payload, $key, true));
    }
}
