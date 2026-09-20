# Switchboard

An inbox and outbox for webhooks in Laravel.

- **Inbox:** receive webhooks that are verified, deduplicated by event ID, stored, and processed on the queue. They can be replayed and reconciled against the provider's API.
- **Outbox:** emit webhooks inside your own database transaction. They are signed with [Standard Webhooks](https://www.standardwebhooks.com/), delivered with retries, and every attempt is logged.

Both directions use one message lifecycle, one signing scheme and one set of ops commands. Everything a platform needs to make its own (models, tenancy, drivers, signing, HTTP, retries) is an extension point.

> **Status: pre-alpha.** This is the package skeleton only. Nothing below exists yet.

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

## Planned

1. **Inbox:** provider drivers (Stripe, GitHub, Standard Webhooks), signature and timestamp verification, event-ID dedupe, queued handlers.
2. **Outbox:** endpoint model, transactional `emit()`, signed delivery with backoff, SSRF guard.
3. **Operations:** replay in both directions, reconciliation, pruning, health checks.

## Development

```bash
composer test   # Pest
composer stan   # PHPStan (Larastan), level 8
composer pint   # Code style
```

## License

MIT. See [LICENSE.md](LICENSE.md).
