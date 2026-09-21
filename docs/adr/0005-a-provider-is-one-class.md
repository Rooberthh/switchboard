# A provider is one class, behind a deliberately large contract

An integration used to be spread over four places: a driver for verifying and reading requests, a handler for acting, three registrations in the static `Switchboard` class (`extend()`, `handledBy()`, `route()`), and a secret under a naming convention in config. The provider key was typed five times, and an integrator reading any one of those places could not see the integration. For a package that sells itself as an SDK, the first half hour is the product, so we optimized for it.

A provider is now **one class** implementing `Contracts\WebhookProvider`, usually by extending `Inbox\WebhookProvider`. It names the provider, returns the `Verification` its requests are proven authentic with, turns a verified request into an inbox message with `toInboxMessage()`, and maps event types to invokable handler classes in `$handlers`. It is registered with one line, `Switchboard::provider(AcmeProvider::class)`, and generated with `php artisan make:webhook-provider`.

**The contract is larger than the 1–3 methods every other contract is held to.** The rule exists because adding a method to an interface breaks every implementor. We accept that cost here in exchange for the whole of an integration being legible from one interface. Before 1.0 it is cheap. After 1.0, a new provider capability is either a major version or travels in `InboxMessageData` as an optional constructor argument, as it always could.

## Consequences

- **`name()` is static.** Registration needs the name to mount the route, and it runs in every process — web, workers, `route:cache`, `config:cache`. A static name means registration builds nothing, so a provider with expensive or database-backed constructor dependencies cannot break boot or caching. Keep names stable: stored messages are found by them.
- **Registration lives in `boot()`, never in a route file.** Route files do not execute once routes are cached, which would leave the registry empty in production — in the queue worker too. `Switchboard::provider()` returns the `Route`, so middleware and naming use Laravel's own routing API rather than a copy of it.
- **The provider never verifies a request itself.** Switchboard calls `verification()->verify()`, so no provider can skip verification by accident. The scheme is a separate class holding the secret it is handed; `StandardWebhooks` is the only one shipped. The HMAC and Standard Webhooks driver base classes are gone, and a generic HMAC verification may return later as its own class. ADR-0001 stands: Switchboard ships schemes, never providers.
- **Handlers are invokable classes**, resolved from the container on the queue. The provider is built during the webhook request in order to verify it, so handler dependencies never ride on the provider's constructor.
- **`InboxMessageData` keeps a required `provider`**, and Switchboard refuses a message whose provider is not the class's `name()`. It is redundant with the class, and kept anyway as an explicit statement in the one method that builds the record.
- **PHP 8.4 is the minimum**, because the contract declares `public array $handlers { get; }` as an interface property. Laravel 13 applications still on 8.3 cannot install the package.
- `tries`, `backoff` and `tolerance` stay configuration; only a verification's tolerance can be set per provider, as a constructor argument.

## Alternatives rejected

- **A small contract and a rich base class.** Identical for integrators who extend the base class, and it would have kept the 1–3 rule. Rejected because the interface is what an integrator reads to learn what a provider is, and a small one hides most of the answer.
- **Registering from a route-file macro.** The most discoverable place for an endpoint, and silently broken under `route:cache`.
- **A list of provider classes in config.** Cacheable, but it puts pointers to behaviour back into a file the package keeps for data.
- **Auto-discovery of classes in `app/Webhooks`.** No registration at all, and nothing in the codebase that says which providers are live.
