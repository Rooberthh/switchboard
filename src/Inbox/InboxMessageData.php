<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Inbox;

use DateTimeInterface;
use Rooberthh\Switchboard\Exceptions\InvalidInboxMessage;

/**
 * What a provider read out of a request: the fields an inbox message is made of.
 *
 * Public API. A field added here as an optional constructor argument is
 * additive, where a method added to the provider contract would break every
 * provider an application has written.
 */
final readonly class InboxMessageData
{
    /**
     * @param  string  $provider  The provider's name — pass static::name(). The inbox refuses a message filed under any other provider.
     * @param  string  $eventId  The provider's identifier for the event; what a message is idempotent on.
     * @param  string  $eventType  The provider's dotted event name, such as "invoice.paid".
     * @param  array<string, mixed>  $data  The normalized payload.
     * @param  string|null  $subject  The provider's identifier for what the message is about, such as "cus_12345".
     * @param  DateTimeInterface|null  $occurredAt  When the provider says the event happened. Receipt time is used when it says nothing.
     */
    public function __construct(
        public string $provider,
        public string $eventId,
        public string $eventType,
        public array $data = [],
        public ?string $subject = null,
        public ?DateTimeInterface $occurredAt = null,
    ) {
        if (trim($provider) === '') {
            throw InvalidInboxMessage::blankProvider();
        }

        // Guarded here rather than at the database, where a blank event id
        // would not violate the unique index — it would quietly match the
        // last event that had one.
        if (trim($eventId) === '') {
            throw InvalidInboxMessage::blankEventId();
        }

        if (trim($eventType) === '') {
            throw InvalidInboxMessage::blankEventType();
        }
    }
}
