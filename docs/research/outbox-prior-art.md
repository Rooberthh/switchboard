# Outbox prior art

Research notes for the `rooberthh/switchboard` outbox design. All findings are from primary
sources: repository source code read at the commits below, plus the Standard Webhooks spec
document itself. No blog posts.

| Source | Commit / version read | Date |
| --- | --- | --- |
| `bambamboole/laravel-webhooks` | `fe2475b` — release 0.4.0 | 2026-08-15 |
| `spatie/laravel-webhook-server` | `main`, requires `illuminate/* ^8.50–^13.0` (the 3.x line; bambamboole pins `^3.10`) | 2026-08-23 |
| `standard-webhooks/standard-webhooks` | `main`, spec version 1.0.0 | 2026-08-31 |

Repos: <https://github.com/bambamboole/laravel-webhooks>,
<https://github.com/spatie/laravel-webhook-server>,
<https://github.com/standard-webhooks/standard-webhooks>.

---

## 1. `bambamboole/laravel-webhooks`

The closest prior art. It is a thin attribute-driven layer **on top of**
`spatie/laravel-webhook-server`, which it declares as a hard dependency
(`composer.json`, `"spatie/laravel-webhook-server": "^3.10"`). It owns subscriptions, payload
shaping and a delivery log; it owns no transport at all.

Requires PHP `^8.4` and `illuminate/* ^13.0`.

### 1.1 Data model

Two tables, both created in `database/migrations/`.

**`webhook_subscriptions`** (`0001_01_01_000001_create_webhook_subscriptions_table.php`):

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `uuid` primary | `HasUuids` |
| `tenant_id` | `uuid` nullable, indexed | Present but *unused by the package* — no code reads or writes it; it exists purely so apps can add tenancy via a model subclass |
| `name` | `string` nullable | |
| `url` | `string` | |
| `secret` | `text` nullable | `'secret' => 'encrypted'` cast (`src/Models/WebhookSubscription.php`) |
| `headers` | `json` | default `{}` |
| `events` | `json` | list of wildcard patterns |
| `active` | `boolean` default `true` | |
| `timestamps` | | |

**`webhook_deliveries`** (`0001_01_01_000002_create_webhook_deliveries_table.php`):

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `uuid` primary | README calls these UUIDv7 |
| `tenant_id` | `uuid` nullable, indexed | again unused internally |
| `subscription_id` | `string` nullable, indexed | **deliberately a plain string, not a FK** — the migration comment says custom repositories may yield ids that are not uuids of `webhook_subscriptions` rows |
| `call_uuid` | `uuid`, indexed | groups the attempts of one spatie `WebhookCall` |
| `event` | `string`, indexed | |
| `url` | `string` | snapshot of the URL used |
| `http_verb` | `string` | |
| `payload` | `json` | full signed body, snapshotted |
| `attempt` | `unsignedSmallInteger` | |
| `status` | `string`, indexed | `succeeded` / `failed` / `final_failed` |
| `response_status` | `unsignedSmallInteger` nullable | |
| `error_type`, `error_message` | `string` / `text` nullable | |
| `timestamps` | | |

**Relationships.** `WebhookDelivery::subscription()` is a `belongsTo` on `subscription_id` with
no database-level foreign key (`src/Models/WebhookDelivery.php`). There is **no message table**.
The event envelope exists only as JSON inside each delivery row, and its `id` (a fresh
`Str::uuid()` per *dispatch*, from `WebhookPayloadFactory::envelope()`) is not a column — it is
passed to spatie only as `meta['payload_id']` and is never persisted as such. So a logical
message fanned out to three endpoints produces three unrelated envelope ids, with no shared key.

### 1.2 How deliveries are dispatched

`src/DispatchWebhookEvent.php` is a plain listener:

1. Look the fired object up in `WebhookEventRegistry`; bail if it carries no `#[WebhookEvent]`.
2. Build the envelope with `WebhookPayloadFactory::make()`.
3. `WebhookSubscriptionRepository::forEvent()` → iterate subscriptions.
4. For each, build a `Spatie\WebhookServer\WebhookCall` and call `->dispatch()`.

`WebhookCall::dispatch()` is `return dispatch($this->callWebhookJob);`
(`spatie/src/WebhookCall.php:250`) — a bare `dispatch()` with **no `afterCommit()`**, no
`->afterCommit` property on `CallWebhookJob`, and no delay. Queueing is therefore queued (not
sync) by default, onto the `webhook-server.queue` connection/queue, but **immediately on
dispatch, not after commit** — unless the app has globally set `after_commit => true` on the
queue connection, which is an app-level setting the package neither sets nor documents.

Recorded state is written *by the queue worker*, after the HTTP call:
`src/RecordWebhookDelivery.php` listens to spatie's `WebhookCallSucceededEvent`,
`WebhookCallFailedEvent` and `FinalWebhookCallFailedEvent` (wired in
`WebhooksServiceProvider::boot()`) and inserts one row per attempt. `FinalWebhookCallFailedEvent`
does not insert; it flips the existing row for that `call_uuid` + `attempt` to `final_failed`.

**Consequence: nothing is written to the database at emit time.** The delivery log is a
*post-hoc log of attempts*, not a queue of intents.

### 1.3 Transactional outbox / sweeper

**Neither exists.** A repo-wide grep over `src/`, `tests/`, `config/` and `database/` for
`afterCommit`, `after_commit`, `transaction`, `sweep`, `relay`, `pending` and
`dispatchAfterResponse` returns **zero matches**. There is:

- no `pending` status (the three statuses are all terminal-ish outcomes of an attempt),
- no `available_at` / `next_attempt_at` / `queued_at` column,
- no scheduled command of any kind (the only commands are `webhooks:cache`, `webhooks:clear`,
  `webhooks:events` — all discovery-cache commands),
- no unique constraint anywhere on `webhook_deliveries` (no idempotency key; grep for
  `unique`, `idempot`, `dedup` returns nothing).

The only scheduled work the README suggests is `model:prune` for log retention
(`webhooks.deliveries.prune_after_days`, default 30).

### 1.4 Event-type matching and wildcards

`WebhookSubscription::candidatePatterns()` (`src/Models/WebhookSubscription.php`) expands an
event name into every pattern that could match it, then the repository does a containment query:

```php
$patterns = [$eventName, '*'];
$segments = explode('.', $eventName);
array_pop($segments);
while ($segments !== []) {
    $patterns[] = implode('.', $segments).'.*';
    array_pop($segments);
}
```

So `invoice.payment.failed` expands to
`['invoice.payment.failed', '*', 'invoice.payment.*', 'invoice.*']`.
`DatabaseWebhookSubscriptionRepository::forEvent()` then runs
`where('active', true)` plus an `orWhereJsonContains('events', $pattern)` per candidate.
Matching is deliberately expressed as containment "so it stays a portable database query" — no
`LIKE`, no suffix wildcards, no mid-segment wildcards. Only exact, dot-boundary prefix, and `*`.

Event names come from the `#[WebhookEvent(name: ..., title:, summary:, tags:)]` attribute
discovered by scanning `webhooks.scan_paths` (default `app_path('Events')`), cached to
`bootstrap/cache/webhooks.php` by `webhooks:cache`.

### 1.5 Secret storage

One nullable `secret` per subscription, `text` column, Laravel `encrypted` cast — so encrypted
at rest with `APP_KEY`, decrypted on read. Format is free-form (README uses
`'secret' => 'signing-secret'`). There is **no `whsec_` prefix, no base64 decoding, no key
rotation support** — one subscription holds exactly one secret at a time, so rotation is a
destructive write. If `secret` is `null`, `DispatchWebhookEvent::send()` calls
`$call->doNotSign()` and the call goes out unsigned.

### 1.6 Retry strategy

Entirely delegated to spatie: 3 tries, exponential backoff `10 ** $attempt` seconds
(see §2.2). bambamboole adds no retry logic, no jitter, no `Retry-After` handling, and no
endpoint auto-disable on repeated failure. Manual replay is
`WebhookDelivery::resend()`, which re-dispatches the *stored payload* through the
subscription's **current** url/secret/headers — note this creates a brand-new `call_uuid`, so
resends are not linked to the original call.

### 1.7 Public extension points

1. **`WebhookSubscriptionRepository`** — one method, `forEvent(string $eventName, object $event): iterable`.
   Bound with `bindIf()` in `register()`, so an app binding wins. Must yield the readonly value
   object `Bambamboole\LaravelWebhooks\WebhookSubscription` (`url`, `secret`, `headers`, `id`);
   `DispatchWebhookEvent` throws a `RuntimeException` on anything else. This is the one real
   seam.
2. **`webhooks.models`** config — swap `subscription` / `delivery` for app subclasses. Resolved
   through the static `Webhooks` class (`Webhooks::subscriptionQuery()`, `deliveryQuery()`,
   `subscriptionModel()`), which validates `is_a(..., $base, true)` and throws otherwise. Note
   this is *config holding behavior*, the opposite of Switchboard's stated rule.
3. **`#[WebhookEvent]` attribute + `webhookPayload()` / `webhookLinks()`** on the event class.
4. **`WebhookEventRegistry::all()`** — read-only; intended to power an app's own subscription UI.
5. **`webhooks.auto_listen => false`** — wire `Event::listen(Foo::class, DispatchWebhookEvent::class)` yourself.
6. **`webhooks.dispatcher.use_timestamp`** — boolean, forwards to spatie's `useTimestamp()`.
7. Indirectly, everything spatie exposes (custom signer, custom `CallWebhookJob`, backoff
   strategy) — but bambamboole does not re-expose these; you configure `webhook-server.php`.

There are **no package-owned events**. `RecordWebhookDelivery` consumes spatie's events; the
package fires none of its own, so an app cannot hook "about to emit" or "delivery succeeded"
except by listening to spatie's transport-level events.

### 1.8 Not confirmed

- Whether delivery `id` is genuinely UUIDv7: the README says so, but the model uses
  `Illuminate\Database\Eloquent\Concerns\HasUuids`, whose `newUniqueId()` is UUID **v4** in
  current Laravel (v7 requires `HasVersion7Uuids`). I did not run it. **The README and the code
  appear to disagree.**
- Download counts / real-world adoption — not checked.

---

## 2. `spatie/laravel-webhook-server`

Reporting only the three things asked.

### 2.1 Transport defaults

From `config/webhook-server.php`:

| Setting | Default |
| --- | --- |
| `timeout_in_seconds` | **3** |
| `tries` | **3** |
| `backoff_strategy` | `ExponentialBackoffStrategy` |
| `queue` / `connection` | `'default'` / `null` |
| `http_verb` | `post` |
| `headers` | `['Content-Type' => 'application/json']` |
| `verify_ssl` | `true` |
| `throw_exception_on_failure` | `false` |
| `proxy` | `null` |
| `signature_header_name` | `'Signature'` |
| `timestamp_header_name` | `'Timestamp'` |

**Backoff** (`src/BackoffStrategy/ExponentialBackoffStrategy.php`):

```php
if ($attempt > 4) { return 100000; }
return 10 ** $attempt;
```

With the default `tries: 3` that is: attempt 1 immediately, retry after **10s**, final retry
after **100s** — total horizon under two minutes. The README confirms this verbatim. Waiting is
implemented as `$this->release($waitInSeconds)` inside `CallWebhookJob::handle()`, i.e. the job
is re-released onto the same queue; there is no jitter.

**Success vs failure** (`src/CallWebhookJob.php`): success is
`Str::startsWith($this->response->getStatusCode(), 2)` — a **string prefix check on "2"**, so
2xx succeeds. Anything else throws `new Exception('Webhook call failed')` and is a failure,
as are Guzzle `RequestException` and `ConnectException` (timeouts, connection resets). On the
last attempt it fires `FinalWebhookCallFailedEvent` and then either `fail()` or `delete()`
depending on `throw_exception_on_failure`. There is **no special handling of `410 Gone`,
`429 Too Many Requests`, or a `Retry-After` header** — grep for `410`, `429`, `retry-after`,
`jitter` across `src/` and `README.md` returns nothing.

**Redirect handling: not configured.** `createRequest()` passes only `timeout`, `verify`,
`headers`, `on_stats`, the body, and optionally `proxy` / `cert` / `ssl_key` to
`Client::request()`. `allow_redirects` is never set, so Guzzle's default applies — redirects
**are followed** (Guzzle default: on, max 5, strict off). I confirmed spatie sets nothing; the
"followed, max 5" part is Guzzle's documented default, not something I read in spatie's source.
This is the opposite of the Standard Webhooks recommendation (§3.5) and a live SSRF concern,
since a redirect can retarget an allow-listed public URL at a private address.

### 2.2 Signing scheme and headers

`src/Signer/DefaultSigner.php`:

```php
$payloadJson = json_encode($payload);
return hash_hmac('sha256', $payloadJson, $secret);   // lowercase hex
```

- Header name: `Signature` (configurable via `signature_header_name`).
- Value: bare lowercase hex HMAC-SHA256 — **no version prefix, no scheme identifier, no
  algorithm agility**, therefore no zero-downtime secret rotation.
- Signed content: **the JSON body only**. Not the URL (it is passed to
  `calculateSignature($webhookUrl, $payload, $secret)` but `DefaultSigner` ignores it), not the
  id, not the timestamp.
- The signature is computed **at dispatch time**, in `WebhookCall::getAllHeaders()`, and frozen
  into the job's `headers` array (`src/WebhookCall.php:315`).
- `useTimestamp()` sets `$job->useTimestamp = true`; the job then does
  `$this->headers[$timestampHeader] = (string) now()->timestamp;` in `addTimestampToHeaders()`,
  **at send time, after the signature was already computed**. So the `Timestamp` header is
  *outside* the signature. A receiver can read it but cannot trust it; it is not bound to the
  body. Contrast Standard Webhooks, where the timestamp is part of the signed string.
- `doNotSign()` omits the header entirely. `prepareForDispatch()` throws
  `CouldNotCallWebhook::secretNotSet()` if signing is on with an empty secret.
- Swappable via the `Signer` interface (`signatureHeaderName()`, `calculateSignature()`), per
  call with `signUsing()` or globally via config. The interface's two methods mean a
  Standard-Webhooks-compatible signer would need three headers but can only name one — the
  `webhook-id` and `webhook-timestamp` headers would have to be injected another way. **I did
  not find a first-party Standard Webhooks signer in this package.**

Note the README documents the `Signer` interface as `calculateSignature(array $payload, string $secret)`
while the actual interface is `calculateSignature(string $webhookUrl, array $payload, string $secret)` —
**the README is out of date** (`src/Signer/Signer.php` vs `README.md`).

### 2.3 How "what to send where" is modelled

Confirmed: **there is no endpoint/subscription model and no persistence of any kind.** The
package ships no migrations and no Eloquent models. A call is built fluently and the URL is
supplied per call:

```php
WebhookCall::create()
    ->url('https://other-app.com/webhooks')
    ->payload(['key' => 'value'])
    ->useSecret('sign-using-this-secret')
    ->dispatch();
```

`WebhookCall::create()` reads defaults from `config('webhook-server')` and returns a builder
that writes straight onto a `CallWebhookJob` instance; `url()` sets
`$this->callWebhookJob->webhookUrl`. `prepareForDispatch()` throws
`CouldNotCallWebhook::urlNotSet()` if you never call `url()`. Fanout, endpoint storage, event
subscription and delivery history are all out of scope — which is exactly the gap
bambamboole fills.

An arbitrary `meta` array rides along on the job and is echoed back on every event
(`WebhookCallEvent::$meta`); this is the hook bambamboole uses to correlate attempts with its
own rows.

---

## 3. `standard-webhooks/standard-webhooks`

Source: `spec/standard-webhooks.md` (spec version 1.0.0, Apache-2.0) and the reference
implementation `libraries/php/src/Webhook.php`.

### 3.1 Signing algorithm

The signed string is the message id, the attempt timestamp and the **raw body**, concatenated
and delimited by **full stops**:

```
msg_id.timestamp.payload
```

Spec, "Signature scheme": *"the message's: ID, timestamp and body are concatenated (delimited by
full-stops) and then signed. The content to be signed is therefore: `msg_id.timestamp.payload`."*
The spec explicitly warns the id and timestamp must not be user-controlled, or at minimum must
not contain a `.`.

PHP reference (`libraries/php/src/Webhook.php`):

```php
$toSign = "{$msgId}.{$timestamp}.{$payload}";
$hex_hash = hash_hmac('sha256', $toSign, $this->secret);
$signature = base64_encode(pack('H*', $hex_hash));
return "v1,{$signature}";
```

So: **HMAC-SHA256 over the raw string, then base64 of the raw digest bytes** (`pack('H*', ...)`
converts the hex digest back to bytes before base64 — *not* base64 of the hex string). The
timestamp is validated as a positive integer before signing.

`$payload` is the raw request body string; the spec stresses that the body sent must be
byte-identical to the body signed, and warns against the common consumer bug of re-serializing
parsed JSON.

Two schemes are defined:

| | Symmetric | Asymmetric |
| --- | --- | --- |
| Algorithm | `HMAC-SHA256` | `ed25519` |
| Key | random, 24–64 bytes (192–512 bits) | ed25519 key pair |
| Secret serialization | base64, prefixed `whsec_` | base64, `whsk_` (private), `whpk_` (public) |
| Signature identifier | `v1` | `v1a` |

Verification uses `hash_equals()` (constant time) in the PHP library; the spec requires a
constant-time comparison for symmetric signatures.

### 3.2 Headers

All headers are prefixed `webhook-`, with exactly these names:

- `webhook-id` — the unique message identifier; **stable across retries**, intended as the
  consumer's idempotency key.
- `webhook-timestamp` — **integer unix timestamp in seconds**; this is the timestamp *of the
  attempt*, updated on every retry, not the event time.
- `webhook-signature` — one or more signatures, **space delimited**.

Each signature is `<version>,<base64>` — version identifier, a **comma**, then the base64
signature. Example from the spec:

```
webhook-id: msg_2KWPBgLlAfxdpx2AI54pPJ85f4W
webhook-timestamp: 1674087231
webhook-signature: v1,K5oZfzN95Z9UVu1EsfQmfVNQhnkZ2pj9o9NDN/H/pI4= v1a,hnO3f9T8Ytu9HwrXslvumlUpqtNVqkhqw/enGzPCXe5BdqzCInXqYXFymVJaA7AZdpXwVLPo3mNl8EM+m7TBAg==
```

The list exists for **zero-downtime secret rotation**: sign with both the new and the old key
for a window, send both, and the consumer accepts if any one matches. The PHP verifier splits
on `' '`, splits each entry on the first `','`, `continue`s on any version that is not `v1`,
and requires one `hash_equals()` match — so an unknown or downgraded scheme identifier is
skipped rather than accepted.

### 3.3 Timestamp tolerance

**The spec text gives no number.** It says only: *"Make sure to verify the `webhook-timestamp`
header has a timestamp that is within some allowable tolerance of the current timestamp to
prevent replay attacks."*

The number lives in the reference implementations, and it is consistently **5 minutes, applied
symmetrically (too old *and* too new)**:

- `libraries/php/src/Webhook.php`: `private const TOLERANCE = 5 * 60;` — rejects
  `$timestamp < $now - TOLERANCE` ("Message timestamp too old") and
  `$timestamp > $now + TOLERANCE` ("Message timestamp too new").
- `libraries/go/webhook.go:25`: `var tolerance time.Duration = 5 * time.Minute`.
- `libraries/python/standardwebhooks/webhooks.py:95`: `webhook_tolerance = timedelta(minutes=5)`.

The spec also recommends using `webhook-id` as an idempotency key and caching seen ids, giving
"save the IDs in redis for 5 minutes" as the example.

### 3.4 Secret format

`whsec_` + base64 of the raw key bytes. The PHP constructor strips the prefix if present, then
`base64_decode`s the remainder and **HMACs with the decoded bytes**, throwing
`EmptyWebhookSecretException` on an empty result. `Webhook::fromRaw($secret)` bypasses both steps
and uses the bytes as given — the escape hatch for legacy non-prefixed secrets.

The spec's rationale for the prefix: *"Having a unique and consistent secret format allows
implementations to correctly use the correct scheme without additional configuration."*
Key length: 24–64 bytes. Keys must be **unique per endpoint**.

### 3.5 Retries, delivery semantics, operational rules

The spec does define these, as recommendations rather than compat requirements.

**Success/failure.** *"A webhook delivery is considered successful if it was responded to with a
`2xx` status code (status codes 200-299), and it is considered a failure in any other scenario."*
Failures include non-2xx, timeouts, connection resets.

**Status code handling:**

| Code | Recommended behavior |
| --- | --- |
| `2xx` | Success |
| `3xx` | **Failure — do not follow redirects.** "Following redirects causes unnecessary load on both the sender and the receiver, it's therefore recommended to update the webhook URL instead." |
| `410 Gone` | Disable the endpoint and stop sending |
| `429` | Rate limit hit — throttle |
| `502` / `504` | Server under load — throttle |
| other | Failure |
| any with `Retry-After` | Honour it when scheduling the next attempt |

**Retry schedule.** Exponential backoff spanning multiple days, **with random jitter**. The
spec's example schedule:

| Delay | Time since start |
| --- | --- |
| Immediately | 00:00:00 |
| 5 seconds | 00:00:05 |
| 5 minutes | 00:05:05 |
| 30 minutes | 00:35:05 |
| 2 hours | 02:35:05 |
| 5 hours | 07:35:05 |
| 10 hours | 17:35:05 |
| 14 hours | 31:35:05 |
| 20 hours | 51:35:05 |
| 24 hours | 75:35:05 |

Ten attempts over ~75 hours. On sustained failure: notify the consumer out of band (email) and
disable the endpoint.

**Request timeout.** Recommended **15–30 seconds** ("enough time to process and acknowledge").
Note spatie's default of 3s is an order of magnitude below this.

**Other operational requirements.** Enforce HTTPS where payloads are sensitive; consider static
source IPs; and an explicit **SSRF** section: proxy all webhook requests through an
internal-IP-filtering proxy (it names Stripe's `smokescreen`) and put workers in a private
subnet with no access to internal services, because "webhooks implementations are especially
vulnerable to SSRF as they let their consumers add any URLs they want."

**Additional functionality** the spec recommends: multi-endpoint fanout, visibility into
failures plus **manual replay of specific messages or ranges**, and an endpoint management API.

**Not defined by the spec:** anything about *producer-side* durability — no mention of a
transactional outbox, of persisting messages before dispatch, of at-least-once guarantees, or of
ordering. The spec is a wire-format and operational-etiquette document. Delivery semantics are
addressed only as "retry until success", which implies at-least-once and is why `webhook-id` is
specified as an idempotency key for the consumer.

---

## 4. Synthesis: what bambamboole does not do that a transactional outbox requires

The differentiator is real. bambamboole/laravel-webhooks is **fanout + a delivery log**; it is
structurally not an outbox, and the gap is not a missing feature but a missing invariant.

A transactional outbox has one defining property: **the decision to send is committed atomically
with the business data, and nothing outside the database is required for the send to eventually
happen.** bambamboole violates this in both halves.

### 4.1 Nothing is written at emit time

`DispatchWebhookEvent::handle()` builds a payload and calls `WebhookCall::dispatch()`. The first
database write happens in `RecordWebhookDelivery`, executed by the **queue worker after the HTTP
attempt has already completed**. Between `event(new InvoicePaid(...))` and the worker running,
the intent to deliver exists only as a job on the queue. If the queue is Redis and Redis loses
the job, or the dispatch happens and the process dies before the queue driver acknowledges, the
event is gone with no trace in the database. There is no row to recover from.

For an outbox this is inverted: `emit()` writes the message and one delivery row per matching
endpoint *first*, in the caller's transaction, and the queue is a fast path, not the system of
record.

### 4.2 No `afterCommit`, so a rolled-back transaction still emits

`WebhookCall::dispatch()` is a bare `dispatch()` (`spatie/src/WebhookCall.php:250`). If the app
fires the event inside `DB::transaction()` and the transaction then rolls back, the job has
already been handed to the queue and the webhook goes out describing a state that never existed.
There is no `afterCommit()`, no `$afterCommit` property on `CallWebhookJob`, and the READMEs of
neither package mention it. The mitigation — setting `after_commit => true` on the queue
connection — is an app-wide setting the package does not set, document, or test. Switchboard's
stated rule ("a rolled-back transaction must not emit", with a test) has no counterpart here.

### 4.3 No `pending` state, so there is nothing for a sweeper to find

The three statuses (`succeeded`, `failed`, `final_failed`) are all outcomes of a completed
attempt. There is no `pending`, no `processing`, no `available_at`/`next_attempt_at`, no
`queued_at`. A relay sweeper needs exactly this: rows that are due but not yet in flight. In
bambamboole, "due but never queued" is not a representable state — a lost job leaves no row at
all, so there is nothing to sweep, and adding a sweeper would require adding the state machine
first.

There is also no scheduled command of any kind besides log pruning, and no way to ask "what
should have gone out but didn't".

### 4.4 No message identity, so no idempotency and no fanout correlation

The envelope `id` is minted fresh per dispatch inside `WebhookPayloadFactory::envelope()` and
never stored as a column. Three endpoints subscribed to one event get three different ids, and a
`resend()` mints yet another `call_uuid`. Consequences:

- No stable `webhook-id` across retries, which Standard Webhooks specifically requires as the
  consumer's idempotency key (§3.2) — spatie's transport sends no such header anyway.
- No unique constraint anywhere, so an at-least-once relay layered on top would produce
  duplicates with no way to deduplicate. Switchboard's "idempotent by event ID" is unachievable
  on this schema.
- No way to answer "did this business event reach all of its endpoints?" — you can only ask
  about individual attempts.

The missing **message** table is the structural tell: bambamboole has endpoint + attempt, and no
row representing "this event, once".

### 4.5 The retry horizon is ~110 seconds, not days

3 tries with 10s/100s backoff (§2.1), no jitter, no `Retry-After`, no `410 Gone` auto-disable,
and a 3-second request timeout against the spec's recommended 15–30s. A receiver that is down
for a five-minute deploy loses the event permanently, with `final_failed` as the only record.
The Standard Webhooks example schedule is ten attempts over 75 hours (§3.5). Long-horizon retry
is not bolt-on: it needs a persisted `next_attempt_at` and a sweeper — i.e. the same machinery
§4.3 is missing.

### 4.6 Signing is not Standard Webhooks and cannot rotate

spatie's `Signature: <hex>` over the body alone, with an unsigned `Timestamp` header added after
the signature is computed (§2.2). No `webhook-id`, no version prefix, no multi-signature list.
That means: no scheme agility, no zero-downtime secret rotation (one `secret` column per
subscription, overwritten destructively), and a timestamp a receiver cannot trust because it is
not covered by the HMAC. A downgraded-scheme test is not even expressible — there is no scheme
identifier to downgrade.

### 4.7 SSRF is unaddressed and redirects are followed

Neither package has any URL validation: grep for `ssrf` in bambamboole returns nothing, and
spatie never sets `allow_redirects`, so Guzzle's default applies and redirects are followed. A
subscription URL is taken verbatim from a database row an end user typically controls. Standard
Webhooks devotes a section to exactly this (§3.5) and recommends treating `3xx` as a failure.

### 4.8 What bambamboole does better, and should be borrowed

Worth saying plainly so the differentiator is not overstated:

- **Attribute-driven event discovery** with a cache command (`webhooks:cache`) is a genuinely
  good DX idea and has no outbox conflict.
- **`candidatePatterns()`** — expanding the event name into candidate patterns so matching stays
  a portable `whereJsonContains` query instead of `LIKE` — is a clean solution to wildcard
  matching and directly reusable.
- **Payload snapshotting** on the delivery row, with `resend()` using the subscription's
  *current* url/secret/headers, is the right call for replay after rotation.
- **The single-method `WebhookSubscriptionRepository` seam** is the right shape and matches
  Switchboard's "contracts of 1–3 methods" rule. (Its `webhooks.models` config, by contrast, is
  behavior in config — precisely what Switchboard's CLAUDE.md forbids, and the static
  `Switchboard` class is the better home.)

### 4.9 Verdict

Switchboard is not re-deriving bambamboole. The overlap is the fanout query and the delivery
log — maybe a third of the surface. Everything the transactional outbox claim rests on is
absent: persist-before-dispatch, `afterCommit`, a `pending` state, a `next_attempt_at`, a relay
sweeper, a message identity with a unique constraint, and at-least-once semantics. Adding those
to bambamboole would mean a new `webhook_messages` table, a rewritten status lifecycle, a new
scheduled command, and abandoning spatie as the transport — i.e. a different package.

The honest framing of the differentiator is: **bambamboole gives at-most-once delivery with an
audit trail of attempts; Switchboard is claiming at-least-once delivery with the send committed
atomically with the business data.** Plus Standard Webhooks signing both ways, which neither
existing package does.
