<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Rooberthh\Switchboard\Actions\Inbox\ReplayAction;
use Rooberthh\Switchboard\Exceptions\IllegalTransition;
use Rooberthh\Switchboard\Jobs\ProcessInboxMessage;

it('returns a failed message to unprocessed and queues it', function () {
    Queue::fake();

    $message = inboxMessage(['failed_at' => now(), 'last_error' => 'an old error']);

    app(ReplayAction::class)->execute($message);

    Queue::assertPushed(
        ProcessInboxMessage::class,
        fn(ProcessInboxMessage $job): bool => $job->inboxMessageId === $message->id,
    );

    expect($message->refresh()->isUnprocessed())->toBeTrue()
        ->and($message->failed_at)->toBeNull()
        ->and($message->last_error)->toBeNull();
});

it('puts the failure back, diagnosis and all, when the queue will not take the job', function () {
    config(['queue.default' => 'database']);

    // The outage that produced the backlog has not finished yet.
    Schema::drop('jobs');

    $failedAt = now()->subHour();
    $message = inboxMessage(['failed_at' => $failedAt, 'last_error' => 'an old error']);

    expect(fn() => app(ReplayAction::class)->execute($message))->toThrow(Exception::class);

    $message->refresh();

    expect($message->isFailed())->toBeTrue()
        ->and($message->failed_at->timestamp)->toBe($failedAt->timestamp)
        ->and($message->last_error)->toBe('an old error');
});

it('refuses to replay a message that succeeded', function () {
    Queue::fake();

    $message = inboxMessage(['processed_at' => now()]);

    expect(fn() => app(ReplayAction::class)->execute($message))->toThrow(IllegalTransition::class);

    Queue::assertNothingPushed();

    expect($message->refresh()->isProcessed())->toBeTrue();
});

it('refuses to replay a message that is merely unprocessed', function () {
    Queue::fake();

    $message = inboxMessage();

    expect(fn() => app(ReplayAction::class)->execute($message))->toThrow(IllegalTransition::class);

    Queue::assertNothingPushed();
});
