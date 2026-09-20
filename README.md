# Switchboard

An inbox and outbox for webhooks in Laravel.

- **Inbox:** receive webhooks that are verified, deduplicated by event ID, stored, and processed on the queue. Anything that failed can be replayed.
- **Outbox:** emit webhooks inside your own database transaction, signed with [Standard Webhooks](https://www.standardwebhooks.com/) and delivered with retries. *Arrives in 0.2.0.*

Both directions share one message lifecycle and one signing scheme. Everything a
platform needs to make its own — drivers, handlers, secrets, routing, queues — is
an extension point.

> **This release (0.1.0) is the inbox and a way to consume it.** The outbox
> follows in 0.2.0.

## Why

Most applications receive webhooks in a controller that verifies a signature by
hand, does the work inline, and returns `200`. That shape fails quietly:

- **The provider retries and the work happens twice.** Nothing records which
  events have been seen, so a timeout on their side becomes a duplicate charge.
- **A handler throws and the event is gone.** Nothing was persisted before the
  work started, so there is nothing to retry — and the provider believes it
  delivered.
- **Verification is subtly wrong.** A `===` instead of a constant-time compare,
  no timestamp window, a re-encoded body. None of these fail loudly; they just
  leave the endpoint forgeable.

Switchboard persists the message **before** anything acts on it, deduplicates on
the event ID, runs your code on the queue, and records what happened.

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

## Quickstart

Three pieces: a **driver** that reads a provider, a **route** that exposes the
endpoint, and a **handler** that acts on what arrives.

**1. Write a driver.** It answers two questions: is this request authentic, and
what does it mean.

```php
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Drivers\StandardWebhooksDriver;
use Rooberthh\Switchboard\Inbox\InboxMessageData;

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
            subject: $payload['data']['customer_id'] ?? null,
            occurredAt: Carbon::parse($payload['timestamp']),
        );
    }
}
```

**2. Write a handler.** One method per event type.

```php
use Rooberthh\Switchboard\Inbox\Handler;
use Rooberthh\Switchboard\Models\InboxMessage;

final class AcmeHandler extends Handler
{
    protected array $handles = [
        'invoice.paid' => 'invoicePaid',
    ];

    public function invoicePaid(InboxMessage $message): void
    {
        Invoice::query()
            ->where('acme_id', $message->subject)
            ->update(['paid_at' => $message->occurred_at]);
    }
}
```

**3. Wire them up** in a service provider's `boot()`:

```php
use Rooberthh\Switchboard\Switchboard;

public function boot(): void
{
    Switchboard::extend('acme', AcmeDriver::class);
    Switchboard::handledBy('acme', AcmeHandler::class);
    Switchboard::route('acme'); // POST /webhooks/acme
}
```

**4. Put the secret in config** — Switchboard never holds one itself:

```php
// config/switchboard.php
'providers' => [
    'acme' => ['secret' => env('ACME_WEBHOOK_SECRET')],
],
```

Standard Webhooks secrets are base64, usually written with a `whsec_` prefix.
A secret that is not valid base64 throws rather than quietly rejecting every
delivery.

Point the provider at `https://your-app.test/webhooks/acme` and run a queue
worker. A delivery is now verified, stored, answered with `204`, and handled on
the queue.

## Writing a driver

Switchboard ships **no provider drivers**, and this is deliberate. Shipping one
is a permanent obligation to track someone else's header format, signature
scheme and event vocabulary, and getting it subtly wrong would be a security bug
in *this* package rather than in your fifteen lines. What it ships instead is an
HMAC base class that owns the parts that are the same everywhere and easy to get
wrong: constant-time comparison, the symmetric timestamp window, and a
conventional place to read a secret from.

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

The three `signed*` methods are the whole of what a provider-specific driver
owes the base class. Everything security-critical stays in the base class, which
is verified against the Standard Webhooks reference vectors — including the
vectors that must *not* verify.

### Providers that sign with Standard Webhooks

`StandardWebhooksDriver` covers the `webhook-id` / `webhook-timestamp` /
`webhook-signature` scheme, so such a driver only says where its payload's
fields are. See the quickstart above. It is a *scheme*, not a provider — this is
also what Switchboard's own outbox will sign with.

### Taking over verification completely

The base class is optional. A driver implementing
`Rooberthh\Switchboard\Contracts\Driver` directly decides for itself what
authentic means, and Switchboard will not second-guess it:

```php
use Rooberthh\Switchboard\Contracts\Driver;

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

Switchboard never holds a secret. `HmacDriver::secret()` reads
`config('switchboard.providers.{provider}.secret')`; override it to read a
per-tenant secret from anywhere else, without touching verification:

```php
protected function secret(): string
{
    return Tenant::current()->acme_webhook_secret;
}
```

## The endpoint

`Switchboard::route()` mounts `POST /webhooks/{provider}`. Both the path and the
middleware are yours to choose:

```php
Switchboard::route('stripe');
Switchboard::route('stripe', path: 'integrations/stripe/inbound');
Switchboard::route('stripe', middleware: ['throttle:webhooks']);
```

It returns the `Route`, so you can decorate it like any other. Registering a
route for a provider with no driver throws **at registration time** and names the
key — a typo fails when your application boots, not by losing a live webhook.

The endpoint is public and unauthenticated by definition, so give it a rate
limit in production. Switchboard does not impose one, because the right limit
depends on the provider's delivery volume:

```php
Switchboard::route('stripe', middleware: ['throttle:120,1']);
```

What a provider sees:

| Response | When |
| --- | --- |
| `204` | The message was verified and stored. |
| `204` | The message was a duplicate. Deliberately indistinguishable: a retry must not trip the provider's alerting. |
| `400` | Anything unverifiable, with no body. Tampered payload, wrong secret and stale timestamp are one undifferentiated answer, so a forger learns nothing. |

Never a `5xx` for a failure in your handler: the response was returned before
processing began.

## Writing a handler

A handler always runs on the queue, against a row that is already stored, so
slow work never blocks the provider and a crash mid-handling cannot lose the
event. Event types map to methods through an explicit registry:

```php
final class StripeHandler extends Handler
{
    protected array $handles = [
        'invoice.paid' => 'invoicePaid',
        'customer.subscription.deleted' => 'subscriptionDeleted',
    ];

    public function invoicePaid(InboxMessage $message): void
    {
        // $message->data is already decoded.
    }
}
```

A registry rather than a derived method name, because derivation collides
silently — `customer.subscription.created` and `customer.subscriptionCreated`
derive to the same name, and routing two different events to the same code is a
bug you find late. If you want derivation anyway, override `methodFor()`:

```php
protected function methodFor(InboxMessage $message): ?string
{
    return Str::camel(str_replace('.', '_', $message->event_type));
}
```

An event type with no method reaches `unhandled()`, which logs a warning rather
than dropping it silently. Override it to throw, to notify, or to ignore:

```php
protected function unhandled(InboxMessage $message): void
{
    // Silence the event types you have decided you do not care about.
}
```

## Lifecycle events

Three events let you watch the parts of the inbox you do not own. They are
observation points — metrics, alerting, notification — never a way to replace
the handler:

| Event | Fired when |
| --- | --- |
| `InboxMessageReceived` | A message was verified and persisted. Dispatched *after commit*, so a listener never sees a message that was rolled back. |
| `InboxMessageProcessed` | A handler returned successfully. |
| `InboxMessageFailed` | The attempts are spent and `failed_at` is set. This is the one to alert on. |

Each carries the message; `InboxMessageFailed` also carries the exception.

```php
use Rooberthh\Switchboard\Events\InboxMessageFailed;

Event::listen(function (InboxMessageFailed $event) {
    OpsChannel::alert($event->message->provider, $event->message->last_error);
});
```

Listening to none of them changes nothing.

## Operating the inbox

### What state is a message in?

Lifecycle is timestamps, not a status column. A message with neither
`processed_at` nor `failed_at` is **unprocessed** — including one a worker is
handling right now, because there is no in-flight state:

```php
InboxMessage::query()->processed()->count();
InboxMessage::query()->failed()->forProvider('stripe')->get();
InboxMessage::query()->unprocessed()->where('created_at', '<', now()->subHour())->get();
```

`last_error` holds the exception that ended the last attempt, so a failure can be
diagnosed without reproducing it.

### Replay

When a bug or an outage in your own code has left messages failed, replay re-runs
them. You do not have to ask the provider to resend anything:

```bash
php artisan switchboard:replay
php artisan switchboard:replay --provider=stripe
```

Replay clears `failed_at` and `last_error` and re-dispatches processing, so the
messages go back to unprocessed and a second run finds nothing. Only failed
messages are eligible — re-running one that succeeded would repeat side effects
your application has already performed.

Replay means **re-run, not re-verify**. An inbox message is a normalized record
rather than a capture of the request, so a stored message's signature can never
be recomputed. Verification happens once, at the edge.

## Configuration

`config/switchboard.php` holds **data only**. Behaviour is configured through the
static `Switchboard` class.

| Key | What it does |
| --- | --- |
| `tables.inbox_messages` | Table name, if `switchboard_inbox_messages` collides with your schema. |
| `queue.connection`, `queue.name` | Where processing jobs go. `null` uses your defaults. |
| `inbox.path` | Prefix for the conventional endpoint. Default `webhooks`. |
| `inbox.tolerance` | Seconds either side of now a signed timestamp may be. Default `300`, and symmetric: too old and too far in the future are both rejected. |
| `inbox.tries` | Attempts a handler gets before the message is recorded as failed. |
| `inbox.backoff` | Seconds between attempts. Exponential by default. |
| `providers.{key}.secret` | The conventional place a driver reads its secret from. Switchboard itself never reads it. |

Configuration mistakes are loud rather than quiet: a secret that cannot be
decoded, or a driver that supplies a blank event id, throws instead of turning
into an endpoint that rejects — or silently discards — every delivery.

## Public API and compatibility

Switchboard follows semantic versioning. What that covers:

| Surface | Kind | Promise |
| --- | --- | --- |
| `Contracts\Driver`, `Contracts\Handler` | Contract | **Adding a method is a breaking change.** Contracts are kept to 1–3 methods for exactly this reason; new information travels in `InboxMessageData` instead, as an optional constructor argument. |
| `Drivers\HmacDriver`, `Drivers\StandardWebhooksDriver`, `Inbox\Handler` | Base class | Meant to be extended. Their protected methods are the seams and change only on a major version. |
| `Inbox\InboxMessageData` | Data object | Grows by appending optional constructor arguments. |
| `Switchboard` | Static entry point | `extend()`, `handledBy()`, `route()`. Behaviour is configured here, never through a facade — there is none. |
| `Events\*` | Events | Observation points. They carry the message and will keep carrying it. |
| `Models\InboxMessage` | Model | Deliberately not `final`; an application may extend it. Nothing in the package names it except one internal resolver, so a model-swap configurator stays a one-line addition. |
| `config/switchboard.php` | Configuration | Data only: table names, queues, tolerances, retries. |
| `Http\*`, `Jobs\*`, `Console\*`, `Inbox\InboxMessages` | **Internal** | `final` and `@internal`. Swap behaviour through a seam above, never by subclassing these. They change without a major version. |

A test asserts this boundary, so it cannot drift silently.

## What Switchboard does not do

- **It ships no provider drivers.** No Stripe, GitHub, Shopify or Slack driver —
  see above, and `docs/adr/0001-no-shipped-provider-drivers.md`.
- **It stores no raw bodies and no headers.** An inbox message is a normalized
  record of what an event means, not an audit log of an HTTP request. If you need
  forensics, add a column to your own extended model
  (`docs/adr/0003-inbox-messages-are-normalized-records.md`).
- **It does not reconcile against a provider's API.** That would require calling
  a provider, reintroducing exactly the coupling this package removes.
- **It does not send webhooks yet.** The outbox — a transactional `emit()`,
  endpoints, deliveries, signed delivery with backoff and an SSRF guard — arrives
  in 0.2.0. The message lifecycle and signing scheme here are designed for both
  directions already (`docs/adr/0002-inbox-first-shared-core-for-both-directions.md`).

## Development

```bash
composer test   # Pest
composer stan   # PHPStan (Larastan), level 8
composer pint   # Code style
```

## License

MIT. See [LICENSE.md](LICENSE.md).
