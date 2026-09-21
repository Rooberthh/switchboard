# Switchboard

An SDK for webhooks in Laravel, covering both directions: an **inbox** that receives them and an **outbox** that sends them, sharing one message lifecycle and one signing scheme. It removes the boilerplate an application would otherwise write around webhooks; it is not a delivery platform and it ships no integrations of its own.

v1 is the inbox and a way to consume it. The outbox follows in 0.2.0, but the shared core is designed against both directions from the start.

## Language

### Shared

**Message**:
A webhook event that Switchboard has persisted, in either direction. Every message carries an event ID and is idempotent on it.

**Event type**:
The dotted name for what happened, such as `invoice.paid`.
_Avoid_: event name, topic, trigger

**Event ID**:
The identifier that makes a message idempotent: the provider's own id on the inbox, and the message's own id on the outbox, sent as `webhook-id`.
_Avoid_: idempotency key, external id

**Relay**:
The scheduled sweep that finds messages or deliveries which are due but were never queued, and queues them. It is what makes processing and delivery at-least-once rather than best-effort. Relaying is not a lifecycle step: a relayed inbox message is still unprocessed.
_Avoid_: sweeper, cron, reaper

**Stale**:
Unprocessed for longer than it could still plausibly be in flight, so its job is presumed lost. Since there is no in-flight state, staleness is an inference from age, never an observation.
_Avoid_: stuck, orphaned, abandoned

### Inbox

**Inbox message**:
An inbound webhook, persisted on arrival before any handling happens. Persisting it is the whole of the inbox's responsibility during the request.
_Avoid_: WebhookCall, call, incoming webhook, notification

**Provider**:
The external system an inbox message arrived from, identified by the key its driver is registered under. That one key is also the name in the route and the value in the message's `provider` column.
_Avoid_: source, sender, integration

**Driver**:
The adapter that teaches Switchboard how to read one provider: whether a request is authentic, and what its event ID and event type are. Applications supply their own; Switchboard ships none.
_Avoid_: adapter, integration, connector, client

**Handler**:
The application class that acts on an inbox message, one method per event type. Handlers always run on the queue, never during the request that delivered the message.
_Avoid_: listener, processor, consumer

**Subject**:
The provider's identifier for what a message is about, such as `cus_12345`. Switchboard stores it and never parses it.
_Avoid_: entity id, object id, resource, target

**Unprocessed**:
A message that has neither succeeded nor failed yet. Switchboard has no in-flight state: a message being worked on right now is still unprocessed.
_Avoid_: pending, queued, in progress

**Marking processed**:
Recording that a handler succeeded. Distinct from *processing*, which is the handler running on the queue: the handler does the work, marking is the record of it, and only the record moves a message out of unprocessed. The same split holds for failing a message.
_Avoid_: completing, finishing, closing

**Reconciliation**:
Asking a provider for the events it says it sent, to find the ones that never arrived.
_Avoid_: backfill, catch-up, sync

### Outbox

**Outbox message**:
An outbound webhook, persisted inside the caller's database transaction. It records what happened once, independently of who it goes to.
_Avoid_: event, notification, payload

**Endpoint**:
A URL that receives outbound messages, together with the event patterns it wants.
_Avoid_: subscription, destination, receiver, webhook

**Delivery**:
One endpoint's copy of one outbox message, and the record of the attempts to deliver it. The split between message and delivery is what makes "which messages did this endpoint never receive" answerable.
_Avoid_: attempt, call, log entry

**Emit**:
To write an outbox message and its deliveries, inside whatever transaction the caller is already in. Emitting never performs HTTP.
_Avoid_: send, dispatch, fire, publish

**Replay**:
Re-running a message that was already persisted, in either direction.
_Avoid_: retry, resend, redeliver
