<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Rooberthh\Switchboard\Events\OutboxMessageEmitted;
use Rooberthh\Switchboard\Exceptions\InvalidOutboxMessage;
use Rooberthh\Switchboard\Models\OutboxMessage;
use Rooberthh\Switchboard\Switchboard;

it('writes exactly one message and returns it', function () {
    $message = Switchboard::emit('invoice.paid', ['invoice' => 'inv_123', 'amount' => 1000]);

    expect($message->is(OutboxMessage::query()->sole()))->toBeTrue()
        ->and($message->event_type)->toBe('invoice.paid')
        ->and($message->payload)->toBe(['invoice' => 'inv_123', 'amount' => 1000])
        ->and($message->relayed_at)->toBeNull();
});

it('generates a unique uuidv7 event id for every message', function () {
    $first = Switchboard::emit('invoice.paid', ['amount' => 1000]);
    $second = Switchboard::emit('invoice.paid', ['amount' => 1000]);

    expect(Str::isUuid($first->event_id))->toBeTrue()
        ->and($first->event_id[14])->toBe('7')
        ->and($first->event_id)->not->toBe($second->event_id);
});

it('renders the standard webhooks envelope once and stores it as the body', function () {
    $this->freezeTime();

    $message = Switchboard::emit('invoice.paid', ['amount' => 1000]);

    expect(json_decode($message->body, true))->toBe([
        'type' => 'invoice.paid',
        'timestamp' => now()->toIso8601ZuluString('microsecond'),
        'data' => ['amount' => 1000],
    ]);
});

it('keeps the stored body when the message is read back', function () {
    $message = Switchboard::emit('invoice.paid', ['amount' => 1000.0, 'note' => 'ünïcode / slashes']);

    expect(OutboxMessage::query()->sole()->body)->toBe($message->body)
        ->and($message->body)->toContain('ünïcode / slashes');
});

it('sends an empty payload as an object', function () {
    $message = Switchboard::emit('invoice.paid');

    expect($message->body)->toContain('"data":{}');
});

it('commits and rolls back with the surrounding transaction', function () {
    try {
        DB::transaction(function (): void {
            Switchboard::emit('invoice.paid', ['amount' => 1000]);

            throw new RuntimeException('the invoice was never paid');
        });
    } catch (RuntimeException) {
    }

    expect(OutboxMessage::query()->count())->toBe(0);

    DB::transaction(fn() => Switchboard::emit('invoice.paid', ['amount' => 1000]));

    expect(OutboxMessage::query()->count())->toBe(1);
});

it('writes the message straight away rather than deferring it', function () {
    // Outside a transaction this is the whole of it: the row is there the
    // moment emit returns. There is no requirement that a transaction exist.
    $message = Switchboard::emit('invoice.paid');

    expect($message->exists)->toBeTrue()
        ->and(OutboxMessage::query()->whereKey($message->getKey())->exists())->toBeTrue();
});

it('performs no http and queues nothing', function () {
    Http::fake();
    Queue::fake();

    Switchboard::emit('invoice.paid', ['amount' => 1000]);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('announces the message only once it has committed', function () {
    $seen = [];

    Event::listen(OutboxMessageEmitted::class, function (OutboxMessageEmitted $event) use (&$seen): void {
        $seen[] = $event->message->event_id;
    });

    DB::beginTransaction();

    $message = Switchboard::emit('invoice.paid');

    expect($seen)->toBeEmpty();

    DB::commit();

    expect($seen)->toBe([$message->event_id]);
});

it('never announces a message that was rolled back', function () {
    $seen = [];

    Event::listen(OutboxMessageEmitted::class, function () use (&$seen): void {
        $seen[] = true;
    });

    DB::beginTransaction();
    Switchboard::emit('invoice.paid');
    DB::rollBack();

    expect($seen)->toBeEmpty();
});

it('refuses a blank event type', function () {
    Switchboard::emit(' ');
})->throws(InvalidOutboxMessage::class);

it('takes its table name from configuration', function () {
    config(['switchboard.tables.outbox_messages' => 'acme_outbox']);

    expect((new OutboxMessage())->getTable())->toBe('acme_outbox');

    $migration = require __DIR__ . '/../../database/migrations/0001_01_01_000001_create_switchboard_outbox_messages_table.php';
    $migration->up();

    expect(Schema::hasTable('acme_outbox'))->toBeTrue();
});
