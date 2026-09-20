# Switchboard ships no provider drivers

Switchboard defines a `WebhookDriver` contract and an HMAC base class, but ships no driver for Stripe, GitHub, Shopify or anyone else. Applications register their own with `Switchboard::extend()`.

A webhook package with no Stripe driver looks like an omission, so: shipping a driver is a permanent obligation to track someone else's header format, signature scheme and event-type vocabulary, and getting it subtly wrong is a security bug in *our* package rather than in the application's fifteen lines. The value Switchboard adds is persist-before-handle, dedupe, lifecycle and replay — none of which is provider-specific. The provider-specific part is small, and the HMAC base class makes it smaller.

## Consequences

- The quickstart cannot open with "install and receive Stripe webhooks"; it opens with writing a driver.
- The contract is exercised only by tests and by applications, never by first-party drivers — so its ergonomics need deliberate attention rather than being proven by use.
- Provider quirks (Stripe's rotating `v1` signatures, Slack's separate timestamp header, Shopify's base64) become README recipes, not code we maintain.
