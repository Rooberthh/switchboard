# The inbox relay infers staleness from age, and relays each message once

A message is persisted before its processing job is queued, so a queue that was unavailable in between leaves the message stored and unprocessed with nothing on its way to handle it. The provider's retry is deduplicated and dispatches nothing, and replay only looks at failures. `switchboard:relay` sweeps up what is left.

The relay cannot ask whether a worker is holding a message right now, because there is no in-flight state — that is the point of ADR-0003's lifecycle and of the glossary's definition of *unprocessed*. So it infers: a message unprocessed for longer than it could still plausibly be in flight is presumed lost. The window is derived from `inbox.tries`, `inbox.backoff` and the queue connection's `retry_after`, doubled. The `retry_after` term is the one that is easy to forget and the one that dominates: a reserved job is invisible to every other worker until it elapses, once per attempt, so an application tuned for long jobs has a legitimate window measured in hours rather than minutes.

**The relay relays a message at most once**, recorded in `relayed_at`. A memoryless sweep amplifies rather than recovers: workers down for three hours, a relay scheduled every five minutes and ten thousand stranded messages produce hundreds of thousands of duplicate jobs, and `ProcessInboxMessage`'s guard is a read rather than a lock, so the duplicates all run. Once is enough to recover from a lost job, and whatever a single relay does not fix was never a lost job.

`relayed_at` is bookkeeping about what the relay has done, **not** a lifecycle state. A relayed message is still unprocessed, `isUnprocessed()` does not consult the column, and the trichotomy stays processed / failed / unprocessed.

## Alternatives rejected

- **Gating the sweep on queue depth.** `Queue::size()` would separate "lost" from "merely slow" exactly. But on a busy application the queue is never empty, so the relay would never fire — useless precisely where it matters, and worse than an age threshold because the failure is silent.
- **Re-dispatching on a duplicate delivery.** Self-healing through the provider's own retries, but it reverses "the first delivery wins", double-processes a message whose job is merely slow, and rests on retries no provider guarantees.
- **An in-flight timestamp.** It would make the inference exact, and it would reverse the lifecycle decision the package is built on.

## Consequences

- **Processing is at-least-once, and now visibly so.** The relay can re-dispatch a message that a worker is still handling, if that worker exceeds the derived window. Handlers must be idempotent — which was already true, because queue retries re-run a handler that throws halfway, and is now documented.
- A relayed message starts again at attempt one, so its lifetime handler runs can exceed `inbox.tries`.
- An application whose handlers legitimately run for longer than the derived window must set `inbox.stale_after` itself.
- A message the relay cannot fix stays unprocessed forever, by design: it is a symptom of something the relay cannot repair, most often workers not consuming the configured queue at all. The command reports these rather than retrying them, which is the only signal an operator gets for that misconfiguration.
- Messages stranded and then recovered are indistinguishable afterwards from messages that were never stranded, except by `relayed_at`.
