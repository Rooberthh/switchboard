<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Inbox\Staleness;

beforeEach(function () {
    config([
        'switchboard.inbox.stale_after' => null,
        'switchboard.inbox.tries' => 5,
        'switchboard.inbox.backoff' => [10, 60, 360, 2160],
        'switchboard.queue.connection' => null,
        'queue.default' => 'database',
        'queue.connections.database.retry_after' => 60,
    ]);
});

it('derives a window wider than the retries it has to outlast', function () {
    // Four releases at 10 + 60 + 360 + 2160, five attempts each invisible for
    // retry_after, doubled for margin.
    expect(Staleness::seconds())->toBe((2590 + 5 * 60) * 2);
});

it('takes an explicitly configured window over the derived one', function () {
    config(['switchboard.inbox.stale_after' => 900]);

    expect(Staleness::seconds())->toBe(900);
});

it('outlasts a queue that hides a reserved job for an hour', function () {
    // A reserved job is invisible to other workers until retry_after elapses,
    // so an application tuned for long jobs has a legitimate window of hours.
    config(['queue.connections.database.retry_after' => 3600]);

    expect(Staleness::seconds())->toBe((2590 + 5 * 3600) * 2);
});

it('repeats the last backoff value when there are more attempts than backoffs', function () {
    // Worker::calculateBackoff falls back to the last entry, so the derivation
    // has to as well or it underestimates the window.
    config(['switchboard.inbox.backoff' => [30]]);

    expect(Staleness::seconds())->toBe((4 * 30 + 5 * 60) * 2);
});

it("reads retry_after from switchboard's own queue connection when it has one", function () {
    config([
        'switchboard.queue.connection' => 'webhooks',
        'queue.connections.webhooks.retry_after' => 1800,
    ]);

    expect(Staleness::seconds())->toBe((2590 + 5 * 1800) * 2);
});

it('falls back to a sane retry_after for a connection that declares none', function () {
    config(['queue.default' => 'sqs', 'queue.connections.sqs' => ['driver' => 'sqs']]);

    expect(Staleness::seconds())->toBe((2590 + 5 * 60) * 2);
});

it('is never shorter than a single attempt, whatever the configuration', function () {
    config(['switchboard.inbox.tries' => 0, 'switchboard.inbox.backoff' => []]);

    expect(Staleness::seconds())->toBeGreaterThan(0);
});

it('offers the cutoff as a moment, not a duration', function () {
    $this->freezeTime();

    expect(Staleness::cutoff()->timestamp)->toBe(Carbon::now()->subSeconds(Staleness::seconds())->timestamp);
});
