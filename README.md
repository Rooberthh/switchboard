# Switchboard

An inbox and outbox for webhooks in Laravel.

- **Inbox:** receive webhooks that are verified, deduplicated by event ID, stored, and processed on the queue. They can be replayed and reconciled against the provider's API.
- **Outbox:** emit webhooks inside your own database transaction. They are signed with [Standard Webhooks](https://www.standardwebhooks.com/), delivered with retries, and every attempt is logged.

Both directions use one message lifecycle, one signing scheme and one set of ops commands. Everything a platform needs to make its own (models, tenancy, drivers, signing, HTTP, retries) is an extension point.

> **Status: pre-release.** The inbox is being built towards 0.1.0. The outbox follows in 0.2.0.

## Requirements

- PHP 8.3+
- Laravel 13

## Installation

```bash
composer require rooberthh/switchboard
php artisan vendor:publish --tag=switchboard-config
php artisan vendor:publish --tag=switchboard-migrations
php artisan migrate
```

## Writing a driver

Switchboard ships no provider drivers, and this is deliberate: shipping one is a
permanent obligation to track someone else's header format and signature scheme,
and getting it subtly wrong would be a security bug in *this* package rather than
in your fifteen lines. What it ships instead is the HMAC base class below, which
owns the parts that are the same everywhere and easy to get wrong — constant-time
comparison, the symmetric timestamp window, and a conventional place to read a
secret from.

A driver answers two questions: is this request authentic, and what does it mean.

### A driver for Stripe

```php
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Drivers\HmacDriver;
use Rooberthh\Switchboard\Inbox\InboxMessageData;

final class StripeDriver extends HmacDriver
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
            static fn (string $pair): string => explode('=', $pair, 2)[1],
            array_filter($pairs, static fn (string $pair): bool => str_starts_with($pair, "{$key}=")),
        ));
    }
}
```

Register it, and mount its endpoint:

```php
// In a service provider's boot method
Switchboard::extend('stripe', StripeDriver::class);
Switchboard::route('stripe'); // POST /webhooks/stripe
```

The secret is read from `config('switchboard.providers.stripe.secret')`, so
nothing but your configuration ever holds it:

```php
'providers' => [
    'stripe' => ['secret' => env('STRIPE_WEBHOOK_SECRET')],
],
```

### A provider that signs with Standard Webhooks

Providers that follow [Standard Webhooks](https://www.standardwebhooks.com/) —
`webhook-id`, `webhook-timestamp` and `webhook-signature` headers — need only
say where their payload's fields are:

```php
use Rooberthh\Switchboard\Drivers\StandardWebhooksDriver;

final class AcmeDriver extends StandardWebhooksDriver
{
    protected function provider(): string
    {
        return 'acme';
    }

    public function normalize(Request $request): InboxMessageData
    {
        $payload = $request->json()->all();

        return new InboxMessageData(
            eventId: (string) $request->header('webhook-id'),
            eventType: $payload['type'],
            data: $payload['data'],
        );
    }
}
```

### Taking over verification completely

The base class is optional. A driver that implements
`Rooberthh\Switchboard\Contracts\Driver` directly decides for itself what
authentic means, and Switchboard will not second-guess it:

```php
final class InternalDriver implements Driver
{
    public function verify(Request $request): bool
    {
        return $request->hasValidSignature(); // or mTLS, or a shared token
    }

    public function normalize(Request $request): InboxMessageData { /* ... */ }
}
```

### Where the secret lives

Switchboard never holds a secret. `HmacDriver::secret()` reads the conventional
config location; override it to read a per-tenant secret from anywhere else
without touching verification:

```php
protected function secret(): string
{
    return Tenant::current()->stripe_webhook_secret;
}
```

## Outbox

Emitting webhooks — a transactional `emit()`, endpoints, deliveries, signed
delivery with backoff and an SSRF guard — arrives in 0.2.0. The message
lifecycle and signing scheme in this release are designed for both directions.

## Development

```bash
composer test   # Pest
composer stan   # PHPStan (Larastan), level 8
composer pint   # Code style
```

## License

MIT. See [LICENSE.md](LICENSE.md).
