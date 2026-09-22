# The outbox relay is the only path to an endpoint

Emitting writes one row: the outbox message. It does not look up endpoints, create deliveries or queue a job. A scheduled sweep, the relay, which each application schedules itself, turns committed messages into one delivery per subscribed endpoint, and queues every delivery whose next attempt is due. Long retry waits are stored on the delivery as its next attempt time, not held as queue delays.

The alternative was a fast path: queue a job per message after commit, and keep the sweep only for jobs that were lost, the way the inbox relay works (ADR-0004). It would start deliveries within a second rather than at the next scheduled run. We chose one mechanism over two. Emitting stays a single insert — the cheapest thing to put inside someone else's transaction, and the application decides whether that transaction exists at all — and there is no second path whose failures the sweep has to cover.

Storing the next attempt time follows from the same choice, and from the Standard Webhooks retry schedule: about ten attempts over three days. A queue cannot reliably hold a job for hours — SQS caps a delay at fifteen minutes — and a delayed job lost in a queue outage leaves no trace. A stored time is recovered by the next sweep on any driver.

## Consequences

- A message reaches its endpoints at the relay's next run, up to the schedule interval after it commits — about a minute with `everyMinute()`.
- An outbox that nobody schedules sends nothing. The README says so first, and an application sees the messages pile up unrelayed.
- The endpoints a message goes to are the ones subscribed when the relay creates its deliveries, not when it was emitted.
- `relayed_at` on the message is bookkeeping, set in the same transaction as its deliveries, and a unique index on message and endpoint guards against a relay creating them twice.
