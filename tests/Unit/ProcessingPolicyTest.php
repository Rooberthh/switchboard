<?php

declare(strict_types=1);

use Rooberthh\Switchboard\Jobs\Inbox\ProcessInboxMessage;
use Rooberthh\Switchboard\Support\Inbox\ProcessingPolicy;

it('reads tries and backoff from config', function () {
    config(['switchboard.inbox.tries' => 3, 'switchboard.inbox.backoff' => [5, 50]]);

    expect(ProcessingPolicy::tries())->toBe(3)
        ->and(ProcessingPolicy::backoff())->toBe([5, 50])
        ->and(ProcessingPolicy::unlimited())->toBeFalse();
});

it('treats zero tries as unlimited, as the queue does', function () {
    config(['switchboard.inbox.tries' => 0]);

    expect(ProcessingPolicy::tries())->toBe(0)
        ->and(ProcessingPolicy::unlimited())->toBeTrue();
});

it('gives a negative number of tries a single attempt', function () {
    config(['switchboard.inbox.tries' => -3]);

    expect(ProcessingPolicy::tries())->toBe(1);
});

it('falls back to the default backoff when none is configured', function (mixed $backoff) {
    config(['switchboard.inbox.backoff' => $backoff]);

    expect(ProcessingPolicy::backoff())->toBe(ProcessingPolicy::DEFAULT_BACKOFF);
})->with([[[]], [null], [['soon', 'later']]]);

it('keeps only the numeric backoff entries, as whole seconds', function () {
    config(['switchboard.inbox.backoff' => [10, 'soon', '60', 360.0]]);

    expect(ProcessingPolicy::backoff())->toBe([10, 60, 360]);
});

it('is what the processing job retries by', function () {
    config(['switchboard.inbox.tries' => 0, 'switchboard.inbox.backoff' => ['soon']]);

    $job = new ProcessInboxMessage(1);

    expect($job->tries)->toBe(ProcessingPolicy::tries())
        ->and($job->backoff())->toBe(ProcessingPolicy::backoff());
});
