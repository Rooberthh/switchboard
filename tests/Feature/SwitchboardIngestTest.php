<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Rooberthh\Switchboard\Events\InboxMessageReceived;
use Rooberthh\Switchboard\Exceptions\UnknownProvider;
use Rooberthh\Switchboard\Inbox\InboxMessageData;
use Rooberthh\Switchboard\Jobs\ProcessInboxMessage;
use Rooberthh\Switchboard\Models\InboxMessage;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Tests\Fixtures\AcmeStandardWebhooksProvider;
use Rooberthh\Switchboard\Tests\Fixtures\FakeProvider;
use Rooberthh\Switchboard\Tests\Fixtures\RecordingHandler;

beforeEach(function () {
    RecordingHandler::$calls = [];

    Switchboard::provider(FakeProvider::class);
});

/**
 * A message the test vouches for, as an application would for one it
 * verified by other means.
 *
 * @param  array<string, mixed>  $overrides
 */
function vouchedFor(array $overrides = []): InboxMessageData
{
    return new InboxMessageData(...[
        'provider' => 'acme',
        'eventId' => 'evt_1',
        'eventType' => 'invoice.paid',
        'data' => ['invoice_id' => 'inv_1'],
        ...$overrides,
    ]);
}

it('runs the handler the provider maps the event type to, and marks the message processed', function () {
    config(['queue.default' => 'sync']);

    Switchboard::ingest(vouchedFor());

    expect(RecordingHandler::$calls)->toBe(['invoice.paid:evt_1'])
        ->and(InboxMessage::query()->sole()->processed_at)->not->toBeNull();
});

it('refuses a provider nobody registered, before storing anything', function () {
    Queue::fake();

    expect(fn() => Switchboard::ingest(vouchedFor(['provider' => 'globex'])))
        ->toThrow(UnknownProvider::class);

    expect(InboxMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('hands an unmapped event type to the provider', function () {
    config(['queue.default' => 'sync']);

    $provider = new class extends FakeProvider {
        public function unhandled(InboxMessage $message): void
        {
            RecordingHandler::$calls[] = "unhandled:{$message->event_type}";
        }
    };

    Switchboard::flush();
    Switchboard::provider($provider::class);

    Switchboard::ingest(vouchedFor(['eventType' => 'invoice.voided']));

    expect(RecordingHandler::$calls)->toBe(['unhandled:invoice.voided']);
});

it('returns the stored message', function () {
    Queue::fake();

    $message = Switchboard::ingest(vouchedFor(['subject' => 'cus_12345']));

    expect($message->is(InboxMessage::query()->sole()))->toBeTrue()
        ->and($message->subject)->toBe('cus_12345')
        ->and($message->data)->toBe(['invoice_id' => 'inv_1']);
});

it('keeps the first message for an event ID and queues a repeat nothing', function () {
    Queue::fake();

    $first = Switchboard::ingest(vouchedFor(['data' => ['invoice_id' => 'inv_1']]));
    $repeat = Switchboard::ingest(vouchedFor(['data' => ['invoice_id' => 'inv_2']]));

    expect($repeat->is($first))->toBeTrue()
        ->and($repeat->data)->toBe(['invoice_id' => 'inv_1']);

    Queue::assertPushedTimes(ProcessInboxMessage::class, 1);
});

it('announces the message only once the surrounding transaction commits', function () {
    Queue::fake();

    $seen = [];

    Event::listen(InboxMessageReceived::class, function () use (&$seen): void {
        $seen[] = true;
    });

    DB::beginTransaction();

    Switchboard::ingest(vouchedFor());

    expect($seen)->toBeEmpty();

    DB::commit();

    expect($seen)->toHaveCount(1);
});

it('stores, queues and announces nothing when the surrounding transaction rolls back', function () {
    // A real queue: the fake ignores the after-commit hold.
    config(['queue.default' => 'database']);

    $seen = [];

    Event::listen(InboxMessageReceived::class, function () use (&$seen): void {
        $seen[] = true;
    });

    DB::beginTransaction();
    Switchboard::ingest(vouchedFor());
    DB::rollBack();

    expect($seen)->toBeEmpty()
        ->and(InboxMessage::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it("carries the readme's recipe for testing a provider's parsing on its own", function () {
    $request = Request::create('/webhooks/acme', 'POST', server: ['HTTP_WEBHOOK_ID' => 'evt_1'], content: (string) json_encode([
        'type' => 'invoice.paid',
        'data' => ['customer_id' => 'cus_1'],
    ]));

    $data = app(AcmeStandardWebhooksProvider::class)->toInboxMessageData($request);

    expect($data->eventId)->toBe('evt_1')
        ->and($data->eventType)->toBe('invoice.paid')
        ->and($data->subject)->toBe('cus_1');
});
