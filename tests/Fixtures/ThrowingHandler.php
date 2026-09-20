<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests\Fixtures;

use Rooberthh\Switchboard\Inbox\Handler;
use Rooberthh\Switchboard\Models\InboxMessage;
use RuntimeException;

class ThrowingHandler extends Handler
{
    public static int $attempts = 0;

    /** @var list<string> */
    public static array $succeedFrom = [];

    protected function methodFor(InboxMessage $message): ?string
    {
        return 'always';
    }

    public function always(InboxMessage $message): void
    {
        self::$attempts++;

        if (in_array($message->event_id, self::$succeedFrom, true)) {
            return;
        }

        throw new RuntimeException("the ledger rejected {$message->event_id}");
    }
}
