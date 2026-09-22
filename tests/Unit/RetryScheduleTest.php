<?php

declare(strict_types=1);

use Rooberthh\Switchboard\Support\RetrySchedule;

beforeEach(function () {
    $this->freezeTime();
});

function scheduleWithJitter(float $random, ?array $waits = null): RetrySchedule
{
    return new RetrySchedule($waits, fn(): float => $random);
}

it('follows the standard webhooks example schedule with no jitter', function () {
    $schedule = scheduleWithJitter(0.0);

    $waits = collect(range(1, 9))->map(fn(int $made): int => (int) now()->diffInSeconds($schedule->next($made)))->all();

    expect($waits)->toBe([5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400]);
});

it('gives ten attempts, then none', function () {
    expect(scheduleWithJitter(0.0)->next(9))->not->toBeNull()
        ->and(scheduleWithJitter(0.0)->next(10))->toBeNull();
});

it('stretches each wait by at most ten percent', function () {
    $longest = scheduleWithJitter(0.9999)->next(2);

    expect(now()->diffInSeconds($longest))->toBeGreaterThan(300.0)
        ->and(now()->diffInSeconds($longest))->toBeLessThanOrEqual(330.0);
});

it('actually varies the wait by default', function () {
    $schedule = new RetrySchedule();

    $waits = collect(range(1, 20))->map(fn(): int => (int) now()->diffInSeconds($schedule->next(5)))->unique();

    expect($waits->count())->toBeGreaterThan(1)
        ->and($waits->min())->toBeGreaterThanOrEqual(18000)
        ->and($waits->max())->toBeLessThanOrEqual(19800);
});

it('honours a longer retry-after', function () {
    expect(now()->diffInSeconds(scheduleWithJitter(0.0)->next(1, retryAfter: 120)))->toBe(120.0);
});

it('ignores a retry-after shorter than the schedule', function () {
    expect(now()->diffInSeconds(scheduleWithJitter(0.0)->next(2, retryAfter: 10)))->toBe(300.0);
});

it('caps a retry-after at the longest wait in the schedule', function () {
    expect(now()->diffInSeconds(scheduleWithJitter(0.0)->next(1, retryAfter: 60 * 60 * 24 * 365)))->toBe(86400.0);
});

it('takes the schedule from configuration', function () {
    config(['switchboard.outbox.retry_schedule' => [60, 120]]);

    $schedule = scheduleWithJitter(0.0);

    expect(now()->diffInSeconds($schedule->next(1)))->toBe(60.0)
        ->and(now()->diffInSeconds($schedule->next(2)))->toBe(120.0)
        ->and($schedule->next(3))->toBeNull();
});

it('treats an empty schedule as one attempt only', function () {
    expect(scheduleWithJitter(0.0, [])->next(1))->toBeNull();
});
