# v1 is the inbox; the shared core is designed for both directions anyway

Switchboard's premise is that one package serves both directions. v1 (0.1.0) nonetheless ships only the inbox and a way to consume it; the outbox arrives in 0.2.0.

The outbox is not deferred because it is doubtful — the transactional outbox is the package's strongest differentiator, and the prior-art research in `docs/research/outbox-prior-art.md` confirms nothing in the Laravel ecosystem does it. It is deferred because the inbox has to stand alone as a shippable thing first, so the shared core gets tested against a real consumer before a second one is built on top of it. The core is therefore designed against both directions from the start, even where only one direction uses it yet.

## Consequences

- Decisions that look over-general for an inbox-only package (message/delivery vocabulary, a signing scheme with no inbound caller) are deliberate.
- The absence of the outbox is a sequencing choice, not a scope rejection. Do not design the inbox in ways that foreclose it.
