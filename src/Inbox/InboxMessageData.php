<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Inbox;

use DateTimeInterface;

/**
 * What a driver read out of a request: the fields an inbox message is made of.
 *
 * Public API. This is the growth point of the driver contract — a field added
 * here as an optional constructor argument is additive, where a method added to
 * the contract itself would break every driver an application has written.
 */
final readonly class InboxMessageData
{
    /**
     * @param  string  $eventId  The provider's identifier for the event; what a message is idempotent on.
     * @param  string  $eventType  The provider's dotted event name, such as "invoice.paid".
     * @param  array<string, mixed>  $data  The normalized payload.
     * @param  string|null  $subject  The provider's identifier for what the message is about, such as "cus_12345".
     * @param  DateTimeInterface|null  $occurredAt  When the provider says the event happened. Receipt time is used when it says nothing.
     */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public array $data = [],
        public ?string $subject = null,
        public ?DateTimeInterface $occurredAt = null,
    ) {}
}
