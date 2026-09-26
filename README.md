# Switchboard

An inbox and outbox for webhooks in Laravel.

- **Inbox:** receive webhooks that are verified, deduplicated by event ID, stored, and processed on the queue. Anything that failed can be replayed.
- **Outbox:** emit webhooks inside your own database transaction, signed with [Standard Webhooks](https://www.standardwebhooks.com/) and delivered at least once, with retries over days.

Both directions share one message lifecycle and one signing scheme. An
integration is one provider class; everything a platform needs to make its own —
verification, handlers, secrets, routing, queues — is an extension point.

> **0.2.0 adds the outbox.** 0.1.0 was the inbox and a way to consume it.

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

    public function toInboxMessageData(Request $request): InboxMessageData
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
| `toInboxMessageData()` | What a verified request means, as the inbox message to store. Pass `provider: static::name()`; a message filed under any other name is refused. |
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

    public function toInboxMessageData(Request $request): InboxMessageData
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

### Ingesting a message yourself

Not every event arrives as a POST to the endpoint. When your own code holds a
message it trusts — events it fetched from a provider's API after an outage, or
ones delivered through a channel you already authenticate — ingest it:

```php
use Rooberthh\Switchboard\Inbox\InboxMessageData;
use Rooberthh\Switchboard\Switchboard;

foreach ($acme->eventsSince($outageStartedAt) as $event) {
    Switchboard::ingest(new InboxMessageData(
        provider: AcmeProvider::name(),
        eventId: $event['id'],
        eventType: $event['type'],
        data: $event['data'],
    ));
}
```

The message goes through everything a verified request does. It is stored once
per event ID, so an event that did arrive is not handled twice; its handler is
queued and `InboxMessageReceived` fires, both after commit. `ingest()` returns
the stored message, and throws for a provider name nobody registered.

**Ingesting verifies nothing.** You are vouching for the message, so never
ingest anything you have not authenticated yourself — least of all the body of
a request you did not verify. Switchboard still never calls a provider;
ingesting is how *your* code reconciles.

## Sending webhooks

The outbox is the other half: your application **emits** a message, and
Switchboard delivers it to every **endpoint** subscribed to its event type,
signed with Standard Webhooks, retried for days, and never to your own network.

### Quickstart

**1. Schedule the relay.** Do this first: the relay is the only thing that
turns emitted messages into deliveries, and **an outbox nobody relays sends
nothing**.

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('switchboard:outbox:relay')->everyMinute()->withoutOverlapping();
```

Run a queue worker too; each delivery attempt is a queued job.

**2. Create an endpoint.** Usually from a settings screen where your customer
enters their URL:

```php
use Rooberthh\Switchboard\Models\Endpoint;

$endpoint = Endpoint::query()->create([
    'url' => 'https://customer.example/webhooks',
    'event_types' => ['invoice.paid', 'invoice.voided'],
]);

$endpoint->secret; // whsec_... — generated, stored encrypted. Show it to the customer once.
```

Endpoints subscribe to exact event types. There are no wildcards.

**3. Emit.** Inside the transaction that makes the event true:

```php
use Rooberthh\Switchboard\Switchboard;

DB::transaction(function () use ($invoice) {
    $invoice->markPaid();

    Switchboard::emit('invoice.paid', [
        'invoice' => $invoice->public_id,
        'amount' => $invoice->amount,
    ]);
});
```

Within a minute the relay creates a delivery to every subscribed endpoint and
queues it.

### Emitting

`Switchboard::emit($eventType, $payload)` writes **one row** and does nothing
else — no endpoints are looked up, no job is queued, no HTTP is performed. So
**you decide whether it is atomic**: emit inside `DB::transaction()` and the
message commits or rolls back with your own writes, so a rolled-back change is
never announced; emit outside one and it commits on its own.

Switchboard generates the message's event ID, a UUIDv7 sent to receivers as
`webhook-id`, and renders the envelope every receiver gets, once, at emit:

```json
{"type":"invoice.paid","timestamp":"2026-09-22T10:00:00.000000Z","data":{"invoice":"inv_123","amount":1000}}
```

That exact body is stored and sent to every endpoint on every attempt, so a
signature always covers the same bytes and a later code change never alters a
message already emitted (`docs/adr/0006-the-outbox-stores-the-rendered-body.md`).

### Idempotency keys

Emitting twice makes two messages. When the code that emits can run twice — a
queued job that is retried after it committed — pass an idempotency key:

```php
Switchboard::emit('invoice.paid', $payload, idempotencyKey: "invoice.paid:{$invoice->id}");
```

The same key within 24 hours returns the first message and writes nothing.
Reusing it with a different event type or payload throws, because silently
returning the first message would drop the second. After 24 hours the key is
free again (`outbox.idempotency_window`).

### Delivery and retries

Each attempt sends the stored body with `webhook-id`, `webhook-timestamp` and
`webhook-signature`, signed with the endpoint's current secret — so rotating a
leaked secret takes effect on the next attempt. The URL is fixed when the
delivery is created.

| Answer | What happens |
| --- | --- |
| `2xx` | Delivered. |
| `3xx` | A failure. The redirect is **never followed**. |
| `410 Gone` | The endpoint is **disabled** and receives nothing further. |
| `429`, `502`, `504` | Retried, honouring `Retry-After` when it asks for longer. |
| Anything else, or a timeout | Retried. |

Failures are retried on the Standard Webhooks schedule — **ten attempts over
about three days**: immediately, then after 5 seconds, 5 and 30 minutes, and
2, 5, 10, 14, 20 and 24 hours, each stretched a little at random. The waits
live on the delivery, not on your queue, so they survive a queue outage and
work on SQS (`docs/adr/0007-the-outbox-relay-is-the-only-path-to-an-endpoint.md`).
When the schedule is spent the delivery is marked failed; the endpoint keeps
receiving new messages.

**Delivery is at-least-once, and unordered.** A receiver may see a message
twice — it should deduplicate on `webhook-id` — and may see `invoice.paid`
before `invoice.created` when the first attempt of one failed. Order by the
envelope's `timestamp`, never by arrival.

### Endpoints are someone else's input

Every attempt resolves the endpoint's host, refuses it unless every address it
resolves to is on the public internet — private ranges, loopback, link-local and
cloud metadata, carrier-grade NAT and the rest — and connects to the address it
checked, so DNS rebinding cannot slip past. Only `http` and `https` are
delivered to.

For local development or a genuinely internal receiver, allow its host
explicitly:

```php
// config/switchboard.php
'outbox' => [
    'allowed_hosts' => ['localhost'],
],
```

### Replay

Once a receiver has fixed its side, send its failed deliveries again:

```bash
php artisan switchboard:outbox:replay
php artisan switchboard:outbox:replay --endpoint=42
```

Replayed deliveries are due at once and the relay sends them on its next run —
the same body to the same URL, with the retry schedule started over.

### Outbox events

| Event | Fired when |
| --- | --- |
| `OutboxMessageEmitted` | A message was emitted and committed. |
| `OutboxDeliverySucceeded` | An endpoint answered `2xx`. |
| `OutboxDeliveryFailed` | A delivery failed for good: its attempts are spent, or its endpoint is gone. The one to tell your customer about. |
| `EndpointDisabled` | An endpoint answered `410 Gone`. |

All are dispatched after commit, and there is no event per failed attempt.

### Keeping endpoints in your own storage

Endpoints live in Switchboard's `switchboard_endpoints` table by default.
If your application already stores its customers' webhook URLs — its own
table, one per tenant, an external service — bind your own implementation of
`Contracts\Endpoints` instead. It has three methods:

```php
use Rooberthh\Switchboard\Contracts\Endpoints;
use Rooberthh\Switchboard\Outbox\EndpointData;

final class TenantEndpoints implements Endpoints
{
    /** Active endpoints subscribed to exactly this event type. */
    public function subscribedTo(string $eventType): iterable
    {
        return WebhookUrl::query()
            ->whereNull('disabled_at')
            ->whereJsonContains('events', $eventType)
            ->lazy()
            ->map(fn (WebhookUrl $url) => new EndpointData(
                id: (string) $url->id,
                url: $url->url,
                secret: $url->signing_secret,
            ));
    }

    /** One active endpoint, or null when it is gone or disabled. */
    public function find(string $id): ?EndpointData
    {
        $url = WebhookUrl::query()->whereNull('disabled_at')->find($id);

        return $url ? new EndpointData((string) $url->id, $url->url, $url->signing_secret) : null;
    }

    /** Its receiver answered 410 Gone. */
    public function disable(string $id): void
    {
        WebhookUrl::query()->whereKey($id)->update(['disabled_at' => now()]);
    }
}
```

```php
// AppServiceProvider::register()
$this->app->bind(Endpoints::class, TenantEndpoints::class);
```

The id is recorded on every delivery as a string, with no foreign key, so it
can be anything stable. Secrets must be Standard Webhooks secrets: `whsec_`
and base64. To keep an existing secret on Switchboard's own table, pass it
when creating the endpoint; otherwise one is generated.

## Testing

### Reaching a handler

A test that goes through the endpoint has to sign a request in the provider's
scheme. To test the handler map and your handlers end to end without one,
[ingest](#ingesting-a-message-yourself) the message:

```php
use Rooberthh\Switchboard\Inbox\InboxMessageData;
use Rooberthh\Switchboard\Switchboard;

it('marks the invoice paid', function () {
    $invoice = Invoice::factory()->create(['acme_id' => 'in_123']);

    Switchboard::ingest(new InboxMessageData(
        provider: AcmeProvider::name(),
        eventId: 'evt_1',
        eventType: 'invoice.paid',
        subject: 'in_123',
    ));

    expect($invoice->fresh()->paid_at)->not->toBeNull();
});
```

On the `sync` queue, which Laravel's `phpunit.xml` uses by default, the handler
runs before `ingest()` returns. The message `ingest()` returns is as it was
stored, so read `fresh()` to see it processed.

### Testing a provider's parsing

Ingesting skips `toInboxMessageData()`. Test that on its own, with a request
built the way the provider sends it:

```php
use Illuminate\Http\Request;

it('reads an acme request', function () {
    $request = Request::create('/webhooks/acme', 'POST', server: ['HTTP_WEBHOOK_ID' => 'evt_1'], content: json_encode([
        'type' => 'invoice.paid',
        'data' => ['customer_id' => 'cus_1'],
    ]));

    $data = app(AcmeProvider::class)->toInboxMessageData($request);

    expect($data->eventId)->toBe('evt_1')
        ->and($data->eventType)->toBe('invoice.paid')
        ->and($data->subject)->toBe('cus_1');
});
```

## Configuration

`config/switchboard.php` holds **data only**. Behaviour lives in your provider
classes, registered through the static `Switchboard` class.

| Key | What it does |
| --- | --- |
| `tables.*` | Table names, if the defaults collide with your schema. |
| `queue.connection`, `queue.name` | Where processing jobs and delivery attempts go. `null` uses your defaults. |
| `inbox.path` | Prefix for the conventional endpoint. Default `webhooks`. |
| `inbox.tolerance` | Seconds either side of now a signed timestamp may be. Default `300`, and symmetric: too old and too far in the future are both rejected. |
| `inbox.tries` | Attempts a handler gets before the message is recorded as failed. |
| `inbox.backoff` | Seconds between attempts. Exponential by default. |
| `inbox.stale_after` | Seconds a message may be unprocessed before `switchboard:relay` treats its job as lost. `null` derives it from the two rows above and your queue's `retry_after`. |
| `providers.{name}.secret` | Where a provider's `secret()` reads from by default. Switchboard itself never reads it. |
| `outbox.idempotency_window` | Seconds an idempotency key holds. Default `86400`. |
| `outbox.timeout` | Seconds a delivery waits for the endpoint. Default `15`. |
| `outbox.retry_schedule` | Seconds to wait after each failed attempt. Default: the Standard Webhooks schedule, ten attempts over about three days. |
| `outbox.lease` | Seconds a queued delivery is left alone before its job is presumed lost and it is queued again. Default `300`. |
| `outbox.allowed_hosts` | Hosts delivered to even though they resolve to a private address. Default none. |

Configuration mistakes are loud rather than quiet: a secret that cannot be
decoded, or a provider that supplies a blank event id, throws instead of turning
into an endpoint that rejects — or silently discards — every delivery.

## Public API and compatibility

Switchboard follows semantic versioning. What that covers:

| Surface | Kind | Promise |
| --- | --- | --- |
| `Contracts\WebhookProvider` | Contract | **Adding a method is a breaking change.** Deliberately larger than the other contracts, so an integration reads as one class (`docs/adr/0005-a-provider-is-one-class.md`). New information travels in `InboxMessageData` instead, as an optional constructor argument. |
| `Contracts\Verification` | Contract | One method, and kept that way. |
| `Contracts\Endpoints` | Contract | Three methods, and kept that way. `Outbox\EndpointData` is what it hands out, and grows by optional constructor arguments. |
| `Inbox\WebhookProvider` | Base class | Meant to be extended. Its methods are the seams and change only on a major version. |
| `Verification\StandardWebhooks` | Verification | The shipped signature scheme. `final`: another scheme is another `Verification`. |
| `Inbox\InboxMessageData` | Data object | Grows by appending optional constructor arguments. |
| `Switchboard` | Static entry point | `provider()`, `resolve()`, `providers()`, `emit()`, `ingest()`. Providers are registered and messages emitted and ingested here, never through a facade — there is none. |
| `Events\*` | Events | Observation points. They carry the message and will keep carrying it. |
| `Models\InboxMessage` | Model | Deliberately not `final`; an application may extend it. Nothing in the package names it except one internal resolver, so a model-swap configurator stays a one-line addition. |
| `Models\OutboxMessage`, `Models\Endpoint`, `Models\Delivery` | Models | Deliberately not `final`. |
| `config/switchboard.php` | Configuration | Data only: table names, queues, tolerances, retries. |
| `Actions\*`, `Http\*`, `Jobs\*`, `Console\*`, `Support\*`, `Inbox\InboxMessages`, `Inbox\Staleness`, `Outbox\DatabaseEndpoints` | **Internal** | `final` and `@internal`. Swap behaviour through a seam above, never by subclassing these. They change without a major version. |

A test asserts this boundary, so it cannot drift silently.

## What Switchboard does not do

- **It ships no provider classes.** No Stripe, GitHub, Shopify or Slack
  provider — see above, and `docs/adr/0001-no-shipped-provider-drivers.md`.
- **It stores no raw bodies and no headers.** An inbox message is a normalized
  record of what an event means, not an audit log of an HTTP request. If you need
  forensics, add a column to your own extended model
  (`docs/adr/0003-inbox-messages-are-normalized-records.md`).
- **It does not reconcile against a provider's API.** That would require calling
  a provider, reintroducing exactly the coupling this package removes. Your own
  code can: fetch what the provider says it sent and
  [ingest](#ingesting-a-message-yourself) it, and whatever already arrived is
  deduplicated.
- **It promises no delivery order.** Receivers order by the envelope's
  timestamp. Holding back an endpoint's queue behind one failing message would
  let a single bad delivery block a customer for days.
- **It does not backfill.** A new endpoint receives what is emitted after it
  subscribes; it is not sent the history.
- **It does not rotate endpoint secrets with overlap yet.** Replacing a secret
  takes effect on the next attempt; signing with old and new together, as
  Standard Webhooks allows, is planned.

## Development

```bash
composer test   # Pest
composer stan   # PHPStan (Larastan), level 8
composer pint   # Code style
```

## License

MIT. See [LICENSE.md](LICENSE.md).
