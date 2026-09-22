# Switchboard

`rooberthh/switchboard` is a Laravel package providing an **inbox** (receiving webhooks) and an **outbox** (sending webhooks) with one shared core. Think of it as an SDK for webhooks: it removes the boilerplate an application writes around them and ships no integrations of its own.

Read `CONTEXT.md` for the vocabulary and `docs/adr/` for the decisions before designing anything new. The Obsidian design doc at `~/Documents/Brain/1-Projects/Laravel Webhook Package.md` is **superseded** for anything it disagrees with: its task list and schema predate the design work and the repo is now the source of truth.

**v1 (0.1.0) is the inbox and a way to consume it.** The outbox follows in 0.2.0. See ADR-0002.

## Commands

```bash
composer test          # Pest 5 + Orchestra Testbench 11
composer stan          # PHPStan level 8 via Larastan
composer pint          # Pint, preset "per" (pint.json)
```

Supported: PHP ^8.4, Laravel 13 only.

## Thesis

- Inbox and outbox mirror each other: persist first, then work on the queue. Messages are idempotent by event ID.
- Lifecycle is timestamps, not an enum: `processed_at`, `failed_at`, `last_error`. There is no in-flight state, so a message being worked on right now is still **unprocessed**.
- An inbox message is a normalized record, not a capture of the request: no raw body, no headers. Replay therefore means re-run, never re-verify. See ADR-0003.
- An integration is **one provider class** (`Inbox\WebhookProvider`): name, verification, normalization and the event-type → handler map. See ADR-0005.
- Verification is delegated to the `Verification` class a provider returns. The package ships `StandardWebhooks` but **never holds a secret** and ships no provider classes. See ADR-0001.
- Standard Webhooks (`webhook-id`, `webhook-timestamp`, `webhook-signature`, signing `id.timestamp.body`, base64) is the outbox's signing scheme, and is available inbound to any driver that wants it.
- The outbox is a **transactional outbox**. `emit()` writes one row, inside the caller's DB transaction if there is one, and nothing else. A scheduled relay, the only path to an endpoint, turns messages into deliveries and queues what is due, giving at-least-once delivery. See ADR-0006 and ADR-0007.

## Extension points (integration-first)

The package works with zero config, and anything a platform needs to make its own is swappable. There is one rule for *how*:

- **Config** holds data only: table names, queues, timeouts, tolerances.
- **Provider classes** hold behavior. **The static `Switchboard` class** is where they are registered, from a service provider's `boot()`: `Switchboard::provider(AcmeProvider::class)`, and later the `use*Model()` configurators. This follows Cashier/Passport. **There is no facade.** Never register from a route file: those do not run under `route:cache`.
- **Contracts** are bound in the container.
- **Events** let apps react, not replace.

Add a seam only for a real integration need, never for internal layering.

## BC rules

- Every contract is public API. Keep contracts to 1–3 methods and ship a base class or implementation next to each one. Adding a method to an interface is a breaking change. The one deliberate exception is `Contracts\WebhookProvider` (ADR-0005).
- Internals (jobs, middleware, controllers, relay) are `final` and `@internal`. Users swap behavior through a seam, never by subclassing internals.
- Every extension point gets a test that swaps it and a README recipe.

## Security

Test the properties, not just the happy path. These must be **rejected** in tests:

- tampered body
- wrong secret
- stale timestamp
- downgraded signature scheme
- SSRF targets (private IP, DNS rebinding, redirects)
- a rolled-back transaction must not emit

Compare signatures with `hash_equals()`, and always verify the raw request body.

## Code style

- `declare(strict_types=1)` in every PHP file.
- Laravel-native patterns over cleverness. Match the existing packages in `~/code/packages` (see `insight-api`).

## Agent skills

### Issue tracker

Issues live in GitHub Issues at `Rooberthh/switchboard`, via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five canonical labels, unchanged: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `CONTEXT.md` and `docs/adr/` at the repo root. See `docs/agents/domain.md`.
