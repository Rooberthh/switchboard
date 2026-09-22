<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Rooberthh\Switchboard\Events\OutboxMessageEmitted;
use Rooberthh\Switchboard\Exceptions\InvalidOutboxMessage;
use Rooberthh\Switchboard\Models\OutboxMessage;
use Rooberthh\Switchboard\Switchboard;

it('returns the first message for a repeat with the same key and content', function () {
    $first = Switchboard::emit('invoice.paid', ['amount' => 1000], idempotencyKey: 'invoice.paid:inv_123');
    $second = Switchboard::emit('invoice.paid', ['amount' => 1000], idempotencyKey: 'invoice.paid:inv_123');

    expect($second->is($first))->toBeTrue()
        ->and(OutboxMessage::query()->count())->toBe(1);
});

it('announces only the message it actually wrote', function () {
    Event::fake([OutboxMessageEmitted::class]);

    Switchboard::emit('invoice.paid', ['amount' => 1000], idempotencyKey: 'k');
    Switchboard::emit('invoice.paid', ['amount' => 1000], idempotencyKey: 'k');

    Event::assertDispatchedTimes(OutboxMessageEmitted::class, 1);
});

it('treats a payload that reads back the same as the same payload', function () {
    $first = Switchboard::emit('invoice.paid', ['amount' => 10.5, 'at' => now()->startOfSecond()], idempotencyKey: 'k');

    $this->travel(1)->minutes();

    $second = Switchboard::emit('invoice.paid', ['amount' => 10.5, 'at' => $first->payload['at']], idempotencyKey: 'k');

    expect($second->is($first))->toBeTrue();
});

it('throws, naming the key, when a live key is reused with a different payload', function () {
    Switchboard::emit('invoice.paid', ['amount' => 1000], idempotencyKey: 'invoice.paid:inv_123');

    Switchboard::emit('invoice.paid', ['amount' => 900], idempotencyKey: 'invoice.paid:inv_123');
})->throws(InvalidOutboxMessage::class, 'invoice.paid:inv_123');

it('throws when a live key is reused with a different event type', function () {
    Switchboard::emit('invoice.paid', ['amount' => 1000], idempotencyKey: 'k');

    Switchboard::emit('invoice.voided', ['amount' => 1000], idempotencyKey: 'k');
})->throws(InvalidOutboxMessage::class);

it('frees a key once the window has passed', function () {
    $first = Switchboard::emit('invoice.paid', ['amount' => 1000], idempotencyKey: 'k');

    $this->travel(24)->hours();
    $this->travel(1)->seconds();

    $second = Switchboard::emit('invoice.paid', ['amount' => 900], idempotencyKey: 'k');

    expect($second->is($first))->toBeFalse()
        ->and($second->idempotency_key)->toBe('k')
        ->and($first->refresh()->idempotency_key)->toBeNull()
        ->and(OutboxMessage::query()->count())->toBe(2);
});

it('still holds the key just inside the window', function () {
    $first = Switchboard::emit('invoice.paid', ['amount' => 1000], idempotencyKey: 'k');

    $this->travel(23)->hours();

    expect(Switchboard::emit('invoice.paid', ['amount' => 1000], idempotencyKey: 'k')->is($first))->toBeTrue();
});

it('takes the window from configuration', function () {
    config(['switchboard.outbox.idempotency_window' => 60]);

    $first = Switchboard::emit('invoice.paid', idempotencyKey: 'k');

    $this->travel(61)->seconds();

    expect(Switchboard::emit('invoice.paid', idempotencyKey: 'k')->is($first))->toBeFalse();
});

it('recovers from the unique index rather than reading before writing', function () {
    // A racing emit on another connection cannot be simulated on this one:
    // its insert would sit inside our savepoint and roll back with it. What
    // makes the race safe is that the insert is attempted first and the
    // unique index decides, so assert exactly that.
    Switchboard::emit('invoice.paid', ['amount' => 1000], idempotencyKey: 'k');

    $queries = [];

    // beforeExecuting, not listen: the insert that fails must be seen too.
    DB::connection()->beforeExecuting(function (string $sql) use (&$queries): void {
        if (str_contains($sql, 'switchboard_outbox_messages')) {
            $queries[] = $sql;
        }
    });

    Switchboard::emit('invoice.paid', ['amount' => 1000], idempotencyKey: 'k');

    expect($queries[0])->toStartWith('insert into')
        ->and(OutboxMessage::query()->count())->toBe(1);
});

it('does not abort the surrounding transaction when the key is taken', function () {
    Switchboard::emit('invoice.paid', ['amount' => 1000], idempotencyKey: 'k');

    DB::transaction(function (): void {
        Switchboard::emit('invoice.paid', ['amount' => 1000], idempotencyKey: 'k');

        // Still usable: the failed insert was confined to a savepoint.
        Switchboard::emit('invoice.voided');
    });

    expect(OutboxMessage::query()->count())->toBe(2);
});

it('writes a new message every time when no key is given', function () {
    Switchboard::emit('invoice.paid', ['amount' => 1000]);
    Switchboard::emit('invoice.paid', ['amount' => 1000]);

    expect(OutboxMessage::query()->count())->toBe(2);
});

it('refuses a blank key rather than treating it as none', function () {
    Switchboard::emit('invoice.paid', idempotencyKey: ' ');
})->throws(InvalidOutboxMessage::class);
