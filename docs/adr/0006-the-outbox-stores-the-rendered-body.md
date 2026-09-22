# The outbox stores the body it sends, rendered once at emit

An outbox message stores its event type and payload, and also the exact JSON body it will send: the Standard Webhooks envelope (`type`, `timestamp`, `data`), rendered once when the message is emitted. Every endpoint and every attempt sends those bytes.

This deliberately differs from the inbox, which stores meaning and never bytes (ADR-0003). The inbox made that choice because a received request's bytes belong to someone else and keeping them turns pruning into a data-retention obligation. The outbox's body is ours, and the signature covers its exact bytes. Rebuilding it at each attempt would let a JSON round trip, a change to how the envelope is built, or a deploy between attempts send endpoint B different bytes than endpoint A, or attempt five different bytes than attempt one — for what the glossary defines as a record of what happened, once.

## Consequences

- Replaying a failed delivery sends exactly what was sent before.
- A change to the envelope or to application code after a message is emitted never changes that message.
- The body is stored alongside the payload, so a message costs roughly twice its payload in storage until it is pruned.
- The signed timestamp (`webhook-timestamp`) is still per attempt, as Standard Webhooks requires; only the body is fixed.
