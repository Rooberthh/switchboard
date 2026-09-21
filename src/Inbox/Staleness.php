<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Inbox;

use Illuminate\Support\Carbon;

/**
 * How long a message may sit unprocessed before the relay treats it as lost.
 *
 * There is no in-flight state, so the relay cannot ask whether a worker holds
 * a message right now. It waits out the whole window in which a message could
 * still legitimately be processed, which is longer than it looks:
 *
 *   - the queue releases a failed attempt with a backoff, several times over;
 *   - and a reserved job is invisible to every other worker until the
 *     connection's retry_after elapses, once per attempt.
 *
 * Miss the second term and the relay re-dispatches messages that a worker is
 * part way through handling.
 *
 * @internal
 */
final class Staleness
{
    /**
     * Room for a queue that is merely busy rather than broken.
     */
    private const MARGIN = 2;

    private const DEFAULT_RETRY_AFTER = 60;

    public static function cutoff(): Carbon
    {
        return Carbon::now()->subSeconds(self::seconds());
    }

    public static function seconds(): int
    {
        $configured = config('switchboard.inbox.stale_after');

        if (is_numeric($configured)) {
            return (int) $configured;
        }

        $tries = max(1, (int) config('switchboard.inbox.tries', 5));

        return ($tries * self::retryAfter() + self::backoff($tries)) * self::MARGIN;
    }

    /**
     * The seconds a message spends waiting between attempts.
     *
     * A message is released once per attempt but the last, and Laravel's
     * Worker::calculateBackoff repeats the final entry when there are more
     * attempts than entries — so this has to repeat it too.
     * @param int $tries
     */
    private static function backoff(int $tries): int
    {
        /** @var array<int, int|string> $backoff */
        $backoff = array_values(array_filter(
            (array) config('switchboard.inbox.backoff', []),
            static fn(mixed $seconds): bool => is_numeric($seconds),
        ));

        if ($backoff === []) {
            return 0;
        }

        $total = 0;

        for ($attempt = 1; $attempt < $tries; $attempt++) {
            $total += (int) ($backoff[$attempt - 1] ?? end($backoff));
        }

        return $total;
    }

    /**
     * How long the queue hides a reserved job from other workers. This is the
     * application's setting, not ours — a connection tuned for long jobs moves
     * the window by hours.
     */
    private static function retryAfter(): int
    {
        $connection = config('switchboard.queue.connection') ?: config('queue.default');

        if (! is_string($connection)) {
            return self::DEFAULT_RETRY_AFTER;
        }

        $retryAfter = config("queue.connections.{$connection}.retry_after");

        return is_numeric($retryAfter) ? (int) $retryAfter : self::DEFAULT_RETRY_AFTER;
    }
}
