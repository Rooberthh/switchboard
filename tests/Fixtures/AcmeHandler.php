<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests\Fixtures;

use Rooberthh\Switchboard\Inbox\Handler;
use Rooberthh\Switchboard\Models\InboxMessage;

class AcmeHandler extends Handler
{
    /** @var list<string> */
    public static array $calls = [];

    /** @var array<string, string> */
    protected array $handles = [
        'invoice.paid' => 'invoicePaid',
        // Two event types that a naive derivation would route to the same
        // method, and which must not collide.
        'customer.subscription.created' => 'subscriptionCreated',
        'customer.subscriptionCreated' => 'legacySubscriptionCreated',
        'issue_comment.created' => 'issueCommentCreated',
    ];

    public function invoicePaid(InboxMessage $message): void
    {
        self::$calls[] = "invoicePaid:{$message->event_id}";
    }

    public function subscriptionCreated(InboxMessage $message): void
    {
        self::$calls[] = "subscriptionCreated:{$message->event_id}";
    }

    public function legacySubscriptionCreated(InboxMessage $message): void
    {
        self::$calls[] = "legacySubscriptionCreated:{$message->event_id}";
    }

    public function issueCommentCreated(InboxMessage $message): void
    {
        self::$calls[] = "issueCommentCreated:{$message->event_id}";
    }

    protected function unhandled(InboxMessage $message): void
    {
        self::$calls[] = "unhandled:{$message->event_type}";
    }
}
