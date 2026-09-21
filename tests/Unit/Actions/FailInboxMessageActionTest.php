<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Rooberthh\Switchboard\Actions\FailInboxMessageAction;
use Rooberthh\Switchboard\Events\InboxMessageFailed;
use Rooberthh\Switchboard\Exceptions\IllegalTransition;

it('records the failure and its diagnosis', function () {
    $this->freezeTime();

    $message = inboxMessage();

    app(FailInboxMessageAction::class)->execute($message, new RuntimeException('the ledger rejected evt_1'));

    expect($message->refresh()->isFailed())->toBeTrue()
        ->and($message->failed_at->timestamp)->toBe(now()->timestamp)
        ->and($message->last_error)->toContain('RuntimeException')
        ->and($message->last_error)->toContain('the ledger rejected evt_1');
});

it('bounds last_error, so one pathological exception cannot fill the column', function () {
    $message = inboxMessage();

    app(FailInboxMessageAction::class)->execute($message, new RuntimeException(str_repeat('a', 5000)));

    $lastError = (string) $message->refresh()->last_error;

    // 2000 characters plus the ellipsis Str::limit appends.
    expect(strlen($lastError))->toBeLessThanOrEqual(2003)
        ->and($lastError)->toEndWith('...');
});

it('announces the failure, carrying the exception', function () {
    Event::fake([InboxMessageFailed::class]);

    $message = inboxMessage();
    $exception = new RuntimeException('boom');

    app(FailInboxMessageAction::class)->execute($message, $exception);

    Event::assertDispatched(
        InboxMessageFailed::class,
        fn(InboxMessageFailed $event): bool => $event->message->is($message)
            && $event->exception === $exception,
    );
});

it('refuses to fail a message that has already been processed', function () {
    $message = inboxMessage(['processed_at' => now()]);

    expect(fn() => app(FailInboxMessageAction::class)->execute($message, new RuntimeException('boom')))
        ->toThrow(IllegalTransition::class);

    expect($message->refresh()->isProcessed())->toBeTrue()
        ->and($message->isFailed())->toBeFalse();
});

it('lets the later diagnosis win when a message fails twice', function () {
    $message = inboxMessage();

    app(FailInboxMessageAction::class)->execute($message, new RuntimeException('the first error'));
    app(FailInboxMessageAction::class)->execute($message, new RuntimeException('the second error'));

    expect($message->refresh()->last_error)->toContain('the second error');
});
