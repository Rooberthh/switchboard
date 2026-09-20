# Switchboard

`rooberthh/switchboard` is a Laravel package providing an **inbox** (receiving webhooks) and an **outbox** (sending webhooks) with one shared core. The design doc lives in Roberth's Obsidian vault at `~/Documents/Brain/1-Projects/Laravel Webhook Package.md`. Read it before designing anything new.

## Commands

```bash
composer test          # Pest 5 + Orchestra Testbench 11
composer stan          # PHPStan level 8 via Larastan
composer pint          # Pint, preset "per" (pint.json)
```

Supported: PHP ^8.3, Laravel 13 only.

## Thesis

- Inbox and outbox mirror each other: persist first, then work on the queue. Messages are idempotent by event ID and follow a shared status lifecycle (`pending → processing → succeeded | failed`).
- One signing scheme both ways: Standard Webhooks (`webhook-id`, `webhook-timestamp`, `webhook-signature`, signing `id.timestamp.body`, base64).
- The outbox is a **transactional outbox**. `emit()` writes rows inside the caller's DB transaction, jobs dispatch `afterCommit`, and a relay sweeper gives at-least-once delivery.

## Extension points (integration-first)

The package works with zero config, and anything a platform needs to make its own is swappable. There is one rule for *how*:

- **Config** holds data only: table names, queues, timeouts, tolerances.
- **The static `Switchboard` class** holds behavior, configured from a service provider's `boot()`: `Switchboard::useInboxMessageModel()`, `Switchboard::extend()`, and so on. This follows Cashier/Passport. **There is no facade.**
- **Contracts** are bound in the container.
- **Events** let apps react, not replace.

Add a seam only for a real integration need, never for internal layering.

## BC rules

- Every contract is public API. Keep contracts to 1–3 methods and ship an abstract base class next to each one. Adding a method to an interface is a breaking change.
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
