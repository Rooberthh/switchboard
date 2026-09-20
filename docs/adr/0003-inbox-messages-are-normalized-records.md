# Inbox messages are normalized records, not HTTP captures

An inbox message stores `provider`, `event_id`, `event_type`, `subject`, `data` and `occurred_at` — what the event means. It does not store the raw request body or the request headers.

The obvious alternative is to persist the exact bytes, which is what signature verification operates on. We chose meaning over bytes: Switchboard is an SDK that removes boilerplate, not an audit log, and raw bodies plus full headers turn pruning from housekeeping into a data-retention obligation.

## Consequences

- **Replay means re-run, not re-verify.** A stored message's signature can never be recomputed, and "was this really signed by the provider?" is unanswerable after the fact.
- A driver that mis-parses a payload loses the original irrecoverably; fixing the driver cannot repair messages already stored.
- Verification happens once, at the edge, and its result is not durable. The 400 that rejects a bad signature is the only record that it happened.
- An application needing forensics can add a raw-body column to its own extended model. That is the escape hatch; do not add one to the package.
