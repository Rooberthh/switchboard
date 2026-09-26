# The outbox keeps every delivery attempt

Every attempt at a delivery is written as its own row, appended and never updated: the status the receiver answered (or none, when no response came back), our description of the failure, how long it took, and the first kilobyte of the receiver's response body. The delivery keeps its state — due, delivered or failed, its position in the retry schedule — and a summary of how it last ended in `last_status` and `last_error`, written in the same transaction as the attempt, so the two never disagree.

The delivery first kept only that summary, overwritten at each attempt. After a delivery had failed ten times over three days, all that was left was the tenth answer. The earlier ones are exactly the evidence needed when a receiver says a message never arrived, or when a failure changed shape over time — a `401` from a signature check, then timeouts, then `503`s — and they are the receiver's answers, not something an application can reconstruct.

The inbox does not do the same. Its attempts are runs of the application's own handler, whose failures belong in the application's own logs and exception tracker; the inbox keeps `last_error` and nothing more. The outbox's attempts are conversations with someone else's server, and the record of them is only ever ours.

The response excerpt deliberately bends ADR-0003's rule against keeping third-party bytes. A receiver's body is where it explains a refusal ("signature mismatch", "unknown event type"), and every hosted webhook sender shows it. It is capped at 1024 bytes, scrubbed to valid UTF-8 and stripped of NUL bytes, so what it costs in storage and in retention is bounded, and a response that could not be written cannot leave its delivery retrying forever.

## Consequences

- A delivery has up to one row per attempt — about ten per failing delivery on the default schedule — and pruning, when it comes, has more to remove. Attempts cascade from their delivery, and deliveries from their message, so it stays one delete.
- An attempt exists exactly when the delivery's attempt count goes up: for any answer, a timeout, a refused connection, and an address the SSRF guard refused. An endpoint that is gone ends the delivery with no attempt, since nothing was sent and no retry was spent; `last_error` says why.
- Replay restarts the schedule, so the attempt count starts over, but the attempts already made are kept. A delivery can have more attempts than its count.
- There is still no event per attempt. The rows are the observation; a listener reads `$event->delivery->latestAttempt`.
- The excerpt is read from the response's body stream rather than as a string, but the request must never use Guzzle's `stream` option to avoid downloading a large body: streamed requests go through PHP's stream handler, which ignores the curl option the SSRF guard pins the checked address with.
