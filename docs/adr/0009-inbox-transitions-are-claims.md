# Inbox transitions are claims, and the first terminal state stands

Every change to an inbox message's state is one conditional `UPDATE` that names the state it moves from: marking processed and marking failed only touch a message that is neither, the relay only touches one not yet relayed, and replay only touches one that has failed. A transition whose update matches no row did not happen. It writes nothing, announces nothing and throws nothing, and its caller is told so. All of it lives in one inbox lifecycle module, the inbox's counterpart to the outbox's delivery lifecycle.

The transitions used to read the message, check it in memory and then save it, and the checks were tolerant in some places and threw in others. Marking a message processed twice was allowed and announced it twice. Failing it twice let the later error overwrite the earlier one. Marking a failed message processed threw `IllegalTransition`. Each rule made sense on its own, but at-least-once processing (ADR-0004) produces exactly those overlaps. Suppose the relay re-dispatches a message while its first job is still retrying, and the first job spends its attempts and marks it failed. When the second job's handler then succeeds, marking it processed threw *after* the handler had run, so the queue ran the handler again until that job's attempts were spent too. Two overlapping relay sweeps could also both pass the in-memory `relayed_at` check and dispatch twice, so ADR-0004's "at most once" depended on the application scheduling the relay `withoutOverlapping()`.

## Consequences

- **Processed and failed are final until replay, and whichever is recorded first wins.** A handler that succeeds on a message already marked failed is not recorded as a success. The message stays failed with its original error, and replaying it runs the handler again. Handlers were already required to be idempotent.
- **`InboxMessageProcessed` and `InboxMessageFailed` fire once**, for the transition that won, and again only after a replay. A failed message keeps its first error, not its latest.
- **The relay relays a message at most once, whatever the scheduler does.** `withoutOverlapping()` stays in the README because it saves wasted sweeps, not because correctness depends on it.
- The job's check before running the handler is still a read, not a lock, so a handler can still run twice (ADR-0004). Only the *record* of the outcome is claimed.
- `IllegalTransition` no longer applies to inbox messages. The outbox keeps it for replay until its own transitions become claims.

## Alternatives rejected

- **Claims for the relay and replay only, keeping the throws between processed and failed.** That would have closed the double dispatch but kept the throw-after-handler loop, which re-runs application side effects for nothing.
- **Letting a later outcome overwrite an earlier one** ("processed wins", or "the later error wins"). That records the most recent truth, but it means a terminal state is never terminal, and an event can announce an outcome that a second job then contradicts.
