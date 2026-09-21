# Switchboard

An inbox and outbox for webhooks in Laravel.

- **Inbox:** receive webhooks that are verified, deduplicated by event ID, stored, and processed on the queue. Anything that failed can be replayed.
- **Outbox:** emit webhooks inside your own database transaction, signed with [Standard Webhooks](https://www.standardwebhooks.com/) and delivered with retries. *Arrives in 0.2.0.*

Both directions share one message lifecycle and one signing scheme. An
integration is one provider class; everything a platform needs to make its own —
verification, handlers, secrets, routing, queues — is an extension point.

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

- PHP 8.4+
- Laravel 13

## Installation

```bash
composer require rooberthh/switchboard
php artisan vendor:publish --tag=switchboard-config
php artisan vendor:publish --tag=switchboard-migrations
php artisan migrate
```

## Quickstart

A **provider** is one class. It names the provider, says how its requests are
verified, reads what they mean, and maps event types to the handlers that act on
them.

**1. Generate it.**

```bash
php artisan make:webhook-provider Acme
```

This writes `app/Webhooks/AcmeProvider.php`, ready for a provider that signs
with [Standard Webhooks](https://www.standardwebhooks.com/):

```php
use Illuminate\Http\Request;
use Rooberthh\Switchboard\Contracts\Verification;
use Rooberthh\Switchboard\Inbox\InboxMessageData;
use Rooberthh\Switchboard\Inbox\WebhookProvider;
use Rooberthh\Switchboard\Verification\StandardWebhooks;

final class AcmeProvider extends WebhookProvider
{
    public array $handlers = [
        'invoice.paid' => MarkInvoicePaid::class,
    ];

    public static function name(): string
    {
        return 'acme';
    }

    public function verification(): Verification
    {
        return new StandardWebhooks($this->secret());
    }

    public function normalize(Request $request): InboxMessageData
    {
        $payload = $request->json()->all();

        return new InboxMessageData(
            provider: static::name(),
            eventId: (string) $request->header('webhook-id'),
            eventType: $payload['type'],
            data: $payload['data'],
            subject: $payload['data']['customer_id'] ?? null,
        );
    }
}
```

**2. Register it** in a service provider's `boot()`:

```php
use Rooberthh\Switchboard\Switchboard;

public function boot(): void
{
    Switchboard::provider(AcmeProvider::class); // POST /webhooks/acme
}
```

**3. Set its secret.** Switchboard never holds one itself:

```php
// config/switchboard.php
'providers' => [
    'acme' => ['secret' => env('ACME_WEBHOOK_SECRET')],
],
```

Standard Webhooks secrets are base64, usually written with a `whsec_` prefix.
A secret that is not valid base64 throws rather than quietly rejecting every
delivery.

**4. Write a handler** for each event type you care about. A handler is an
invokable class, built by the container on the queue:

```php
use Rooberthh\Switchboard\Models\InboxMessage;

final class MarkInvoicePaid
{
    public function __invoke(InboxMessage $message): void
    {
        Invoice::query()
            ->where('acme_id', $message->subject)
            ->update(['paid_at' => $message->occurred_at]);
    }
}
```

Point the provider at `https://your-app.test/webhooks/acme` and run a queue
worker. A delivery is now verified, stored, answered with `204`, and handled on
the queue.

## Writing a provider

A provider class is the whole of an integration. Everything it has to say:

| Member | What it says |
| --- | --- |
| `name()` | The provider's name: stored on every message, the route segment and the secret's config key. Static, and keep it stable — stored messages are found by it. |
| `verification()` | How its requests are proven authentic. Switchboard calls it; the provider never verifies a request itself. |
| `normalize()` | What a verified request means. Pass `provider: static::name()`; a message filed under any other name is refused. |
| `$handlers` | Event type to invokable handler class. |
| `unhandled()` | What happens to an event type with no handler. Logs a warning by default. |
| `secret()` | Where the secret comes from. `config('switchboard.providers.{name}.secret')` by default. |

Switchboard ships **no provider classes**, and this is deliberate. Shipping one
is a permanent obligation to track someone else's header format, signature
scheme and event vocabulary, and getting it subtly wrong would be a security bug
in *this* package rather than in your fifteen lines
(`docs/adr/0001-no-shipped-provider-drivers.md`).

### Verification

What it ships instead is the security-sensitive part. `StandardWebhooks` covers
the `webhook-id` / `webhook-timestamp` / `webhook-signature` scheme: a
constant-time comparison over the raw body, a symmetric timestamp window, and a
refusal to trust any signature version but `v1`. It is verified against the
Standard Webhooks reference vectors, including the ones that must *not* verify.
It is a *scheme*, not a provider — this is also what Switchboard's own outbox
will sign with.

The tolerance defaults to `inbox.tolerance`. A provider that needs its own says
so where it builds its verification:

```php
return new StandardWebhooks($this->secret(), tolerance: 600);
```

### A verification for Stripe

Any other scheme is a class implementing `Contracts\Verification`, which has one
method. Stripe signs `<timestamp>.<raw body>`, hex encoded, in a header of its
own:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Contracts\Verification;

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
```

And the provider that uses it:

```php
final class StripeProvider extends WebhookProvider
{
    public array $handlers = [
        'invoice.paid' => MarkInvoicePaid::class,
    ];

    public static function name(): string
    {
        return 'stripe';
    }

    public function verification(): Verification
    {
        return new StripeVerification($this->secret());
    }

    public function normalize(Request $request): InboxMessageData
    {
        $payload = $request->json()->all();

        return new InboxMessageData(
            provider: static::name(),
            eventId: $payload['id'],
            eventType: $payload['type'],
            data: $payload['data']['object'] ?? [],
            subject: $payload['data']['object']['customer'] ?? null,
            occurredAt: Carbon::createFromTimestamp($payload['created']),
        );
    }
}
```

The same contract covers a request you authenticate some other way entirely —
`$request->hasValidSignature()`, mTLS, a shared token. Switchboard will not
second-guess what your verification decides.

### Where the secret lives

Switchboard never holds a secret. `secret()` reads
`config('switchboard.providers.{name}.secret')`; override it to read a
per-tenant secret from anywhere else, without touching verification:

```php
protected function secret(): string
{
    return Tenant::current()->acme_webhook_secret;
}
```

## The endpoint

`Switchboard::provider()` mounts `POST /webhooks/{name}` and returns the
`Route`, so the path and middleware are yours to choose:

```php
Switchboard::provider(StripeProvider::class);
Switchboard::provider(StripeProvider::class, path: 'integrations/stripe/inbound');
Switchboard::provider(StripeProvider::class)->middleware('throttle:webhooks');
```

Register providers in `boot()`, not in a route file: route files do not run once
routes are cached, and the queue worker needs the provider too. Registration
builds nothing — `name()` is static — so a provider with expensive dependencies
costs nothing until a delivery arrives. Registering a class that is not a
provider, or a second provider under a name already taken, throws **at boot**
rather than losing a live webhook.

The endpoint is public and unauthenticated by definition, so give it a rate
limit in production. Switchboard does not impose one, because the right limit
depends on the provider's delivery volume:

```php
Switchboard::provider(StripeProvider::class)->middleware('throttle:120,1');
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
event. It is an invokable class, resolved from the container, so its
dependencies arrive through its constructor:

```php
final class MarkInvoicePaid
{
    public function __construct(private readonly Ledger $ledger) {}

    public function __invoke(InboxMessage $message): void
    {
        // $message->data is already decoded.
    }
}
```

Its provider maps event types to it exactly. An explicit map rather than a
derived name, because derivation collides silently —
`customer.subscription.created` and `customer.subscriptionCreated` derive to the
same name, and routing two different events to the same code is a bug you find
late.

### Handlers must be idempotent

Switchboard deduplicates **deliveries**, not **handler runs**. Those are
different promises, and the difference matters:

- One row per event, always. A provider that retries a delivery it already made
  inserts nothing and runs nothing.
- **Processing is at-least-once.** A handler that throws is retried — five times
  by default — and a handler that threw *after* charging a card will charge it
  again on the next attempt. A handler killed mid-run (a deploy, an OOM, a
  timeout) is retried too, from the beginning. And a message recovered by the
  relay starts again at attempt one, so its lifetime runs can exceed `tries`.

So make the work idempotent: key on `$message->event_id`, guard with a
conditional update, or check before you act.

```php
public function __invoke(InboxMessage $message): void
{
    // Does nothing the second time.
    $updated = Invoice::query()
        ->where('stripe_id', $message->subject)
        ->whereNull('paid_at')
        ->update(['paid_at' => $message->occurred_at]);

    if ($updated === 0) {
        return;
    }

    $this->ledger->chargeOnce($message->event_id);
}
```

Exactly-once would mean the handler and the `processed_at` write committing
together, which Switchboard cannot arrange across your database and your queue.
At-least-once plus an idempotent handler is the arrangement that actually holds.

An event type with no handler reaches the provider's `unhandled()`, which logs a
warning rather than dropping it silently. Override it to throw, to notify, or to
ignore:

```php
public function unhandled(InboxMessage $message): void
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

### Relay

A message is stored *before* its processing job is queued, so a queue that was
down in between leaves the message persisted and unprocessed with nothing on its
way to handle it. The provider's retry cannot heal that — it is deduplicated,
and dispatches nothing — and replay only looks at failures.

The relay is the sweep that closes the gap. Schedule it:

```php
// bootstrap/app.php, or a service provider
Schedule::command('switchboard:relay')->everyFifteenMinutes()->withoutOverlapping();
```

```bash
php artisan switchboard:relay
php artisan switchboard:relay --provider=stripe --limit=200
```

It queues messages that have been unprocessed for longer than they could still
plausibly be in flight — derived from `inbox.tries`, `inbox.backoff` and your
queue connection's `retry_after`, and overridable with `inbox.stale_after`. Set
that yourself if a handler of yours legitimately runs for hours.

**A message is relayed at most once.** That is deliberate: without it, an outage
amplifies, and a relay running every fifteen minutes against ten thousand
stranded messages would queue them again and again. Once is enough to recover
from a lost job, and anything a single relay does not fix is not a lost job.

Which is why the relay is also the alarm. Messages that were relayed and are
*still* unprocessed mean nothing is consuming the queue at all — most often a
`switchboard.queue.name` the deployed workers do not run. The command warns and
logs when it finds them:

```php
InboxMessage::query()->relayed()->unprocessed()->count();
```

Relaying is not a lifecycle step: a relayed message is still **unprocessed**
until a handler says otherwise.

## Configuration

`config/switchboard.php` holds **data only**. Behaviour lives in your provider
classes, registered through the static `Switchboard` class.

| Key | What it does |
| --- | --- |
| `tables.inbox_messages` | Table name, if `switchboard_inbox_messages` collides with your schema. |
| `queue.connection`, `queue.name` | Where processing jobs go. `null` uses your defaults. |
| `inbox.path` | Prefix for the conventional endpoint. Default `webhooks`. |
| `inbox.tolerance` | Seconds either side of now a signed timestamp may be. Default `300`, and symmetric: too old and too far in the future are both rejected. |
| `inbox.tries` | Attempts a handler gets before the message is recorded as failed. |
| `inbox.backoff` | Seconds between attempts. Exponential by default. |
| `inbox.stale_after` | Seconds a message may be unprocessed before `switchboard:relay` treats its job as lost. `null` derives it from the two rows above and your queue's `retry_after`. |
| `providers.{name}.secret` | Where a provider's `secret()` reads from by default. Switchboard itself never reads it. |

Configuration mistakes are loud rather than quiet: a secret that cannot be
decoded, or a provider that supplies a blank event id, throws instead of turning
into an endpoint that rejects — or silently discards — every delivery.

## Public API and compatibility

Switchboard follows semantic versioning. What that covers:

| Surface | Kind | Promise |
| --- | --- | --- |
| `Contracts\WebhookProvider` | Contract | **Adding a method is a breaking change.** Deliberately larger than the other contracts, so an integration reads as one class (`docs/adr/0005-a-provider-is-one-class.md`). New information travels in `InboxMessageData` instead, as an optional constructor argument. |
| `Contracts\Verification` | Contract | One method, and kept that way. |
| `Inbox\WebhookProvider` | Base class | Meant to be extended. Its methods are the seams and change only on a major version. |
| `Verification\StandardWebhooks` | Verification | The shipped signature scheme. `final`: another scheme is another `Verification`. |
| `Inbox\InboxMessageData` | Data object | Grows by appending optional constructor arguments. |
| `Switchboard` | Static entry point | `provider()`, `resolve()`, `providers()`. Providers are registered here, never through a facade — there is none. |
| `Events\*` | Events | Observation points. They carry the message and will keep carrying it. |
| `Models\InboxMessage` | Model | Deliberately not `final`; an application may extend it. Nothing in the package names it except one internal resolver, so a model-swap configurator stays a one-line addition. |
| `config/switchboard.php` | Configuration | Data only: table names, queues, tolerances, retries. |
| `Actions\*`, `Http\*`, `Jobs\*`, `Console\*`, `Inbox\InboxMessages`, `Inbox\Staleness` | **Internal** | `final` and `@internal`. Swap behaviour through a seam above, never by subclassing these. They change without a major version. |

A test asserts this boundary, so it cannot drift silently.

## What Switchboard does not do

- **It ships no provider classes.** No Stripe, GitHub, Shopify or Slack
  provider — see above, and `docs/adr/0001-no-shipped-provider-drivers.md`.
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
