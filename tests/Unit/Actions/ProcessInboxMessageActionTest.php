<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Rooberthh\Switchboard\Actions\ProcessInboxMessageAction;
use Rooberthh\Switchboard\Events\InboxMessageProcessed;
use Rooberthh\Switchboard\Exceptions\IllegalTransition;

it('records that a handler succeeded', function () {
    $this->freezeTime();

    $message = inboxMessage();

    app(ProcessInboxMessageAction::class)->execute($message);

    expect($message->refresh()->isProcessed())->toBeTrue()
        ->and($message->processed_at->timestamp)->toBe(now()->timestamp)
        ->and($message->isUnprocessed())->toBeFalse();
});

it('announces the message as part of the act', function () {
    Event::fake([InboxMessageProcessed::class]);

    $message = inboxMessage();

    app(ProcessInboxMessageAction::class)->execute($message);

    Event::assertDispatched(
        InboxMessageProcessed::class,
        fn(InboxMessageProcessed $event): bool => $event->message->is($message),
    );
});

it('refuses to process a message that has already failed', function () {
    $message = inboxMessage(['failed_at' => now(), 'last_error' => 'boom']);

    expect(fn() => app(ProcessInboxMessageAction::class)->execute($message))->toThrow(IllegalTransition::class);

    expect($message->refresh()->isFailed())->toBeTrue()
        ->and($message->isProcessed())->toBeFalse();
});

it('tolerates a repeat, because two workers can legitimately both run the handler', function () {
    // docs/adr/0004: the relay can re-dispatch a message a worker still holds,
    // and the job's guard is a read rather than a lock.
    $message = inboxMessage();

    app(ProcessInboxMessageAction::class)->execute($message);
    app(ProcessInboxMessageAction::class)->execute($message);

    expect($message->refresh()->isProcessed())->toBeTrue();
});
