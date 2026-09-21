<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Actions;

use Rooberthh\Switchboard\Events\InboxMessageReceived;
use Rooberthh\Switchboard\Inbox\InboxMessageData;
use Rooberthh\Switchboard\Inbox\InboxMessages;
use Rooberthh\Switchboard\Jobs\ProcessInboxMessage;
use Rooberthh\Switchboard\Models\InboxMessage;

/**
 * Persist a verified message and queue it, once per event ID.
 *
 * Idempotent on the provider and event ID: a repeat delivery returns the
 * message already stored, untouched, and queues and announces nothing — the
 * first delivery wins. Only a message this call actually inserted is queued
 * and announced, both after commit, so nothing acts on a message that the
 * surrounding transaction then rolled back.
 *
 * Verification is not part of the act. Whoever calls this has already
 * decided the message is authentic.
 *
 * @internal
 */
final class CreateInboxMessageAction
{
    /**
     * @param InboxMessageData $data
     */
    public function execute(InboxMessageData $data): InboxMessage
    {
        $message = InboxMessages::createOrFirst(
            [
                'provider' => $data->provider,
                'event_id' => $data->eventId,
            ],
            [
                'event_type' => $data->eventType,
                'subject' => $data->subject,
                'data' => $data->data,
                'occurred_at' => $data->occurredAt ?? now(),
            ],
        );

        if ($message->wasRecentlyCreated) {
            ProcessInboxMessage::dispatch($message->id)->afterCommit();

            event(new InboxMessageReceived($message));
        }

        return $message;
    }
}
