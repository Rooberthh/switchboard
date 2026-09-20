<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests\Fixtures;

/**
 * The Standard Webhooks reference vector, as published with the specification
 * and reproduced by its own implementations.
 */
final class StandardWebhooksVector
{
    public const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    public const ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';

    public const TIMESTAMP = 1614265330;

    public const PAYLOAD = '{"test": 2432232314}';

    public const SIGNATURE = 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=';

    /**
     * Sign a body the way the specification says to, for the cases the
     * published vector does not cover.
     * @param string $body
     * @param string $id
     * @param int $timestamp
     * @param string $secret
     */
    public static function sign(string $body, string $id = self::ID, int $timestamp = self::TIMESTAMP, string $secret = self::SECRET): string
    {
        $key = (string) base64_decode(substr($secret, strlen('whsec_')), true);

        return 'v1,' . base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
    }

    /**
     * @return array<string, string>
     * @param string $body
     * @param string $id
     * @param int $timestamp
     * @param string $secret
     */
    public static function headers(string $body = self::PAYLOAD, string $id = self::ID, int $timestamp = self::TIMESTAMP, string $secret = self::SECRET): array
    {
        return [
            'webhook-id' => $id,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => self::sign($body, $id, $timestamp, $secret),
        ];
    }
}
