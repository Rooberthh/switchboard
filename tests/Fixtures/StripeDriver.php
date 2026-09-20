<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Drivers\HmacDriver;
use Rooberthh\Switchboard\Inbox\InboxMessageData;

/**
 * The driver from the README's Stripe recipe, kept here so the recipe is
 * executed rather than merely published.
 */
class StripeDriver extends HmacDriver
{
    protected function provider(): string
    {
        return 'stripe';
    }

    // Stripe signs "<timestamp>.<raw body>" and hex encodes the digest,
    // which is what HmacDriver does by default.
    protected function signedPayload(Request $request): string
    {
        return $this->part($request, 't') . '.' . $request->getContent();
    }

    // Stripe-Signature: t=1614265330,v1=5257a8...,v0=an older scheme
    // Only the v1 signatures are returned, so a downgraded v0 is never trusted.
    protected function signatures(Request $request): array
    {
        return $this->parts($request, 'v1');
    }

    protected function signedAt(Request $request): ?int
    {
        $timestamp = $this->part($request, 't');

        return is_numeric($timestamp) ? (int) $timestamp : null;
    }

    public function normalize(Request $request): InboxMessageData
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        return new InboxMessageData(
            eventId: $payload['id'],
            eventType: $payload['type'],
            data: $payload['data']['object'] ?? [],
            subject: $payload['data']['object']['customer'] ?? null,
            occurredAt: Carbon::createFromTimestamp($payload['created']),
        );
    }

    private function part(Request $request, string $key): ?string
    {
        return $this->parts($request, $key)[0] ?? null;
    }

    /** @return list<string> */
    private function parts(Request $request, string $key): array
    {
        $pairs = explode(',', (string) $request->header('Stripe-Signature'));

        return array_values(array_map(
            static fn(string $pair): string => explode('=', $pair, 2)[1],
            array_filter($pairs, static fn(string $pair): bool => str_starts_with($pair, "{$key}=")),
        ));
    }
}
