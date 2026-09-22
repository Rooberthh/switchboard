<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Support;

use Closure;
use Illuminate\Support\Carbon;

/**
 * When a failed delivery is attempted next, if ever.
 *
 * The default is the Standard Webhooks example schedule: ten attempts over
 * about three days — immediately, then after 5 seconds, 5 and 30 minutes,
 * and 2, 5, 10, 14, 20 and 24 hours. Each wait is stretched by up to ten
 * percent at random, so endpoints that failed together do not retry
 * together. A receiver's Retry-After is honoured when it asks for longer,
 * up to the longest wait in the schedule.
 *
 * Pure: no I/O, and the random source is injectable.
 *
 * @internal
 */
final class RetrySchedule
{
    /**
     * Seconds to wait after each failed attempt; one fewer than the attempts.
     */
    public const DEFAULT = [5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400];

    private const JITTER = 0.1;

    /** @var Closure(): float */
    private readonly Closure $random;

    /**
     * @param  list<int>|null  $waits  Defaults to config('switchboard.outbox.retry_schedule').
     * @param  (Closure(): float)|null  $random  A number in [0, 1). Defaults to a uniform random one.
     */
    public function __construct(private readonly ?array $waits = null, ?Closure $random = null)
    {
        $this->random = $random ?? static fn(): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * @param  int  $attemptsMade  How many attempts have been made so far, the failed one included.
     * @param  int|null  $retryAfter  Seconds the receiver asked to wait, if it said.
     * @return Carbon|null The next attempt's time, or null once the schedule is spent.
     */
    public function next(int $attemptsMade, ?int $retryAfter = null): ?Carbon
    {
        $waits = $this->waits();
        $wait = $waits[$attemptsMade - 1] ?? null;

        if ($waits === [] || $wait === null) {
            return null;
        }

        $seconds = (int) round($wait * (1 + self::JITTER * ($this->random)()));

        if ($retryAfter !== null && $retryAfter > $seconds) {
            $seconds = min($retryAfter, max($waits));
        }

        return Carbon::now()->addSeconds($seconds);
    }

    /**
     * @return list<int>
     */
    private function waits(): array
    {
        /** @var array<int, mixed> $waits */
        $waits = $this->waits ?? (array) config('switchboard.outbox.retry_schedule', self::DEFAULT);

        return array_values(array_map(intval(...), array_filter($waits, is_numeric(...))));
    }
}
