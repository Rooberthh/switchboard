# Coding Standards

The judgement calls a reviewer checks on a diff. `composer check` already enforces the mechanical rules (formatting, `declare(strict_types=1)`, PHPStan level 8, and `final` plus `@internal` on internals via `tests/Feature/PublicApiTest.php`), so they are not repeated here.

## BC rules

- Every contract is public API. Keep contracts to 1–3 methods and ship a base class or implementation next to each one. Adding a method to an interface is a breaking change. The one deliberate exception is `Contracts\WebhookProvider` (ADR-0005).
- Users swap behavior through a seam, never by subclassing internals. A new internal goes under `Actions`, `Console`, `Http`, `Jobs` or `Support`, the namespaces `PublicApiTest` holds to `final` and `@internal`.
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

## Style

Laravel-native patterns over cleverness. Match the existing packages in `~/code/packages` (see `insight-api`).
