<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Rooberthh\Switchboard\Actions\CreateOutboxMessageAction;
use Rooberthh\Switchboard\Events\OutboxMessageEmitted;
use Rooberthh\Switchboard\Models\OutboxMessage;

it('persists the message with a generated event id and its rendered envelope', function () {
    $this->freezeTime();

    $message = app(CreateOutboxMessageAction::class)->execute('invoice.paid', ['amount' => 1000]);

    expect($message->is(OutboxMessage::query()->sole()))->toBeTrue()
        ->and(Str::isUuid($message->event_id))->toBeTrue()
        ->and($message->event_type)->toBe('invoice.paid')
        ->and($message->payload)->toBe(['amount' => 1000])
        ->and($message->idempotency_key)->toBeNull()
        ->and(json_decode($message->body, true))->toBe([
            'type' => 'invoice.paid',
            'timestamp' => now()->toIso8601ZuluString('microsecond'),
            'data' => ['amount' => 1000],
        ]);
});

it('announces the message it wrote', function () {
    Event::fake([OutboxMessageEmitted::class]);

    $message = app(CreateOutboxMessageAction::class)->execute('invoice.paid', []);

    Event::assertDispatched(OutboxMessageEmitted::class, fn(OutboxMessageEmitted $event): bool => $event->message->is($message));
});

it('returns the message holding a taken key untouched, and announces nothing', function () {
    Event::fake([OutboxMessageEmitted::class]);

    $first = app(CreateOutboxMessageAction::class)->execute('invoice.paid', ['amount' => 1000], 'k');
    $second = app(CreateOutboxMessageAction::class)->execute('invoice.voided', ['amount' => 1], 'k');

    // Whether a taken key may be reused is not this act's decision.
    expect($second->is($first))->toBeTrue()
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and($second->event_type)->toBe('invoice.paid')
        ->and(OutboxMessage::query()->count())->toBe(1);

    Event::assertDispatchedTimes(OutboxMessageEmitted::class, 1);
});
