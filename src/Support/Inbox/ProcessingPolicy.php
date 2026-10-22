<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Support\Inbox;

/**
 * How the processing job retries a message, read from config in one place.
 *
 * The job retries by it and Staleness derives the relay's window from it, so
 * the two cannot disagree about how long a message may legitimately be in
 * flight (ADR-0004).
 *
 * @internal
 */
final class ProcessingPolicy
{
    public const DEFAULT_TRIES = 5;

    /**
     * Seconds to wait after each failed attempt but the last.
     */
    public const DEFAULT_BACKOFF = [10, 60, 360, 2160];

    /**
     * The attempts a handler gets. Zero is unlimited, as it is to the queue;
     * a negative number gets a single attempt.
     */
    public static function tries(): int
    {
        $tries = (int) config('switchboard.inbox.tries', self::DEFAULT_TRIES);

        return $tries < 0 ? 1 : $tries;
    }

    public static function unlimited(): bool
    {
        return self::tries() === 0;
    }

    /**
     * The numeric entries of the configured backoff, or the default when
     * there are none.
     *
     * @return non-empty-list<int>
     */
    public static function backoff(): array
    {
        /** @var array<int, mixed> $configured */
        $configured = (array) config('switchboard.inbox.backoff', []);

        $backoff = array_values(array_map(intval(...), array_filter($configured, is_numeric(...))));

        return $backoff === [] ? self::DEFAULT_BACKOFF : $backoff;
    }
}
