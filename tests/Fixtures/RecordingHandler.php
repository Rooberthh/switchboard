<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Tests\Fixtures;

use Rooberthh\Switchboard\Models\InboxMessage;

final class RecordingHandler
{
    /** @var list<string> */
    public static array $calls = [];

    public function __invoke(InboxMessage $message): void
    {
        self::$calls[] = "{$message->event_type}:{$message->event_id}";
    }
}
