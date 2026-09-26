# Switchboard

An SDK for webhooks in Laravel, covering both directions: an **inbox** that receives them and an **outbox** that sends them, sharing one message lifecycle and one signing scheme. It removes the boilerplate an application would otherwise write around webhooks; it is not a delivery platform and it ships no integrations of its own.

v1 is the inbox and a way to consume it. The outbox follows in 0.2.0, but the shared core is designed against both directions from the start.

## Language

### Shared

**Message**:
A webhook event that Switchboard has persisted, in either direction. Every message carries an event ID and is idempotent on it.

**Event type**:
The dotted name for what happened, such as `invoice.paid`.
_Avoid_: event name, topic, trigger, key

**Event ID**:
The identifier that makes a message idempotent: the provider's own id on the inbox, and on the outbox an id Switchboard generates when the message is emitted, sent as `webhook-id`. An application never chooses an outbox event ID; to make emitting idempotent it passes an idempotency key instead.
_Avoid_: external id

**Relay**:
The scheduled sweep that queues whatever is due on the outbox: it turns each committed message not yet relayed into one delivery per endpoint subscribed to its event type, and it queues every delivery whose next attempt has come. It is the only way a message reaches an endpoint, and each application schedules it itself. The inbox's recovery sweep for lost processing jobs currently shares the name.
_Avoid_: sweeper, cron, reaper

**Stale**:
Unprocessed for longer than it could still plausibly be in flight, so its job is presumed lost. Since there is no in-flight state, staleness is an inference from age, never an observation.
_Avoid_: stuck, orphaned, abandoned

### Inbox

**Inbox message**:
An inbound webhook, persisted on arrival before any handling happens. Persisting it is the whole of the inbox's responsibility during the request.
_Avoid_: WebhookCall, call, incoming webhook, notification

**Ingest**:
To put an inbox message into the inbox: stored once per event ID, its handler queued, and announced, all after commit. The endpoint ingests a request once it has verified; an application may ingest a message it verified by other means, and vouches for it by doing so.
_Avoid_: inject, import, push

**Provider**:
The external system an inbox message arrived from. It is represented in the application by exactly one webhook provider class, which names it, says how its requests are verified, reads what they mean, and maps its event types to handlers. The name that class gives is also the route segment, the secret's config key, and the value in the message's `provider` column. Applications write their own provider classes; Switchboard ships none.
_Avoid_: source, sender, integration, driver

**Verification**:
The class a provider's requests are proven authentic with: a signature scheme, holding the secret it is handed but never knowing where that secret lives. Switchboard ships Standard Webhooks; any other scheme is an application's own.
_Avoid_: validator, signer, guard

**Handler**:
The invokable application class that acts on an inbox message, mapped from one event type by its provider. Handlers always run on the queue, never during the request that delivered the message.
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
Asking a provider for the events it says it sent, to find the ones that never arrived. Switchboard never does it; an application may, and ingests what it finds.
_Avoid_: backfill, catch-up, sync

### Outbox

**Outbox message**:
An outbound webhook: an event type and its payload, persisted when it is emitted — inside the caller's database transaction if there is one, so the application decides whether it is atomic with its own writes. It records what happened once, independently of who it goes to.
_Avoid_: event, notification

**Payload**:
What an application emits alongside an event type: the contents of an outbox message. It travels inside the envelope, never on its own.
_Avoid_: data, body

**Envelope**:
The Standard Webhooks JSON shape every outbox message is sent in: its event type, when it was emitted, and its payload. Every receiver gets the same envelope.
_Avoid_: wrapper, body, format

**Idempotency key**:
An optional key an application passes when it emits, such as `invoice.paid:inv_123`. Emitting again with the same key within 24 hours writes nothing and returns the message the first emit wrote; after that, the key is free again. Without one, every emit is a new message.
_Avoid_: key, dedupe key, message key

**Endpoint**:
A URL that receives outbound messages, together with the exact event types it subscribes to. There are no wildcards: an endpoint that wants a new event type subscribes to it by name. An endpoint whose receiver answers `410 Gone` is disabled and receives nothing further.
_Avoid_: subscription, destination, receiver, webhook

**Delivery**:
One endpoint's copy of one outbox message, and the record of the attempts to deliver it. The split between message and delivery is what makes "which messages did this endpoint never receive" answerable.
_Avoid_: attempt, call, log entry

**Emit**:
To write an outbox message: one row, inside whatever transaction the caller is already in. Emitting never creates deliveries and never performs HTTP; both happen afterwards, once the message is committed.
_Avoid_: send, dispatch, fire, publish

**Replay**:
Re-running a message that was already persisted, in either direction.
_Avoid_: retry, resend, redeliver
