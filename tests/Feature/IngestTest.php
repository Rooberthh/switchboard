<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Rooberthh\Switchboard\Contracts\Verification;
use Rooberthh\Switchboard\Exceptions\InvalidInboxMessage;
use Rooberthh\Switchboard\Inbox\InboxMessageData;
use Rooberthh\Switchboard\Models\InboxMessage;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Tests\Fixtures\FakeProvider;
use Rooberthh\Switchboard\Tests\Fixtures\FakeVerification;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    // These suites are about the request, not about what happens after it.
    Queue::fake();

    Switchboard::provider(FakeProvider::class);
});

/**
 * Swap the registered provider for one the test has changed.
 * @param FakeProvider $provider
 */
function replaceProvider(FakeProvider $provider): void
{
    Switchboard::flush();
    Switchboard::provider($provider::class);
}

function deliver(array $payload = []): TestResponse
{
    return test()->postJson('webhooks/acme', array_merge([
        'id' => 'evt_1',
        'type' => 'invoice.paid',
        'data' => ['amount' => 1000],
    ], $payload));
}

it('persists a verified message with every field the provider supplied', function () {
    deliver([
        'subject' => 'cus_12345',
        'occurred_at' => '2026-09-18T10:00:00+00:00',
    ])->assertNoContent();

    $message = InboxMessage::query()->sole();

    expect($message->provider)->toBe('acme')
        ->and($message->event_id)->toBe('evt_1')
        ->and($message->event_type)->toBe('invoice.paid')
        ->and($message->subject)->toBe('cus_12345')
        ->and($message->data)->toBe(['amount' => 1000])
        ->and($message->occurred_at->toIso8601String())->toBe('2026-09-18T10:00:00+00:00')
        ->and($message->processed_at)->toBeNull()
        ->and($message->failed_at)->toBeNull()
        ->and($message->last_error)->toBeNull();
});

it('falls back to receipt time when the provider supplies no timestamp', function () {
    $this->freezeTime();

    deliver()->assertNoContent();

    $message = InboxMessage::query()->sole();

    expect($message->occurred_at->timestamp)->toBe($message->created_at->timestamp);
});

it('persists a null subject when the provider supplies none', function () {
    deliver()->assertNoContent();

    expect(InboxMessage::query()->sole()->subject)->toBeNull();
});

it('accepts a duplicate delivery without inserting a second message', function () {
    deliver()->assertNoContent();
    deliver()->assertNoContent();

    expect(InboxMessage::query()->count())->toBe(1);
});

it('lets the first delivery win: a duplicate leaves the stored message untouched', function () {
    deliver(['data' => ['amount' => 1000]])->assertNoContent();

    $first = InboxMessage::query()->sole();

    deliver(['data' => ['amount' => 9999], 'subject' => 'cus_rewritten'])->assertNoContent();

    $message = InboxMessage::query()->sole();

    expect($message->id)->toBe($first->id)
        ->and($message->data)->toBe(['amount' => 1000])
        ->and($message->subject)->toBeNull();
});

it('deduplicates by recovering from the unique index, not by reading before writing', function () {
    // A racing delivery of the same event commits between verification and our
    // own insert. Only insert-first-and-recover survives this.
    replaceProvider(new class extends FakeProvider {
        public function toInboxMessageData(Request $request): InboxMessageData
        {
            DB::table('switchboard_inbox_messages')->insert([
                'provider' => 'acme',
                'event_id' => 'evt_1',
                'event_type' => 'invoice.paid',
                'subject' => null,
                'data' => '{"racer":true}',
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return new InboxMessageData(provider: 'acme', eventId: 'evt_1', eventType: 'invoice.paid');
        }
    });

    deliver()->assertNoContent();

    expect(InboxMessage::query()->count())->toBe(1)
        ->and(InboxMessage::query()->sole()->data)->toBe(['racer' => true]);
});

it('rejects a request the provider does not verify, and persists nothing', function () {
    replaceProvider(new class extends FakeProvider {
        public function verification(): Verification
        {
            return new FakeVerification(verifies: false);
        }
    });

    deliver()->assertStatus(400)->assertNoContent(400);

    expect(InboxMessage::query()->count())->toBe(0);
});

it('rejects a request whose verification blows up rather than answering', function () {
    replaceProvider(new class extends FakeProvider {
        public function verification(): Verification
        {
            return new class implements Verification {
                public function verify(Request $request): bool
                {
                    throw new RuntimeException('the signature header was not what I expected');
                }
            };
        }
    });

    deliver()->assertStatus(400);

    expect(InboxMessage::query()->count())->toBe(0);
});

it('stores neither the raw body nor the request headers', function () {
    expect(Schema::getColumnListing('switchboard_inbox_messages'))->toBe([
        'id',
        'provider',
        'event_id',
        'event_type',
        'subject',
        'data',
        'occurred_at',
        'processed_at',
        'failed_at',
        'last_error',
        'relayed_at',
        'created_at',
        'updated_at',
    ]);
});

it('keys inbox messages on an auto-incrementing integer', function () {
    deliver(['id' => 'evt_1'])->assertNoContent();
    deliver(['id' => 'evt_2'])->assertNoContent();

    $ids = InboxMessage::query()->orderBy('id')->pluck('id')->all();

    expect($ids)->toBe([1, 2]);
});

it('holds the provider and event id unique', function () {
    $unique = collect(Schema::getIndexes('switchboard_inbox_messages'))
        ->filter(fn(array $index): bool => (bool) $index['unique'])
        ->pluck('columns')
        ->all();

    expect($unique)->toContain(['provider', 'event_id']);
});

it('takes its table name from configuration', function () {
    config(['switchboard.tables.inbox_messages' => 'acme_inbox']);

    expect((new InboxMessage())->getTable())->toBe('acme_inbox');

    Schema::dropIfExists('acme_inbox');

    $migration = require __DIR__ . '/../../database/migrations/0001_01_01_000000_create_switchboard_inbox_messages_table.php';
    $migration->up();

    expect(Schema::hasTable('acme_inbox'))->toBeTrue();
});

it('writes before it reads, so a racing delivery cannot slip in between', function () {
    $queries = [];

    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'switchboard_inbox_messages')) {
            $queries[] = $query->sql;
        }
    });

    deliver()->assertNoContent();

    expect($queries)->not->toBeEmpty()
        ->and($queries[0])->toStartWith('insert into');
});

it('refuses a provider that supplies a blank event id rather than collapsing the dedupe key', function () {
    replaceProvider(new class extends FakeProvider {
        public function toInboxMessageData(Request $request): InboxMessageData
        {
            // The header this provider reads its id from is not being sent.
            return new InboxMessageData(provider: 'acme', eventId: '', eventType: 'invoice.paid');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn() => deliver())->toThrow(InvalidInboxMessage::class);

    expect(InboxMessage::query()->count())->toBe(0);
});

it('refuses a provider that supplies a blank event type', function () {
    expect(fn() => new InboxMessageData(provider: 'acme', eventId: 'evt_1', eventType: ' '))
        ->toThrow(InvalidInboxMessage::class);
});

it('refuses a blank provider', function () {
    expect(fn() => new InboxMessageData(provider: ' ', eventId: 'evt_1', eventType: 'invoice.paid'))
        ->toThrow(InvalidInboxMessage::class);
});

it('refuses a provider that files a message under another provider, and persists nothing', function () {
    replaceProvider(new class extends FakeProvider {
        public function toInboxMessageData(Request $request): InboxMessageData
        {
            return new InboxMessageData(provider: 'stripe', eventId: 'evt_1', eventType: 'invoice.paid');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn() => deliver())->toThrow(InvalidInboxMessage::class, '[acme]');

    expect(InboxMessage::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});
