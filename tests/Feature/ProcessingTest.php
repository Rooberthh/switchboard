<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Rooberthh\Switchboard\Models\InboxMessage;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Tests\Fixtures\FakeProvider;
use Rooberthh\Switchboard\Tests\Fixtures\RecordingHandler;
use Illuminate\Testing\TestResponse;
use Rooberthh\Switchboard\Jobs\ProcessInboxMessage;

beforeEach(function () {
    RecordingHandler::$calls = [];
});

/**
 * Register a provider whose only difference from the fake is its handlers.
 *
 * @param  array<string, class-string>  $handlers
 */
function providerHandling(array $handlers): void
{
    $provider = new class extends FakeProvider {
        /** @var array<string, class-string> */
        public static array $mapped = [];

        public function __construct()
        {
            $this->handlers = self::$mapped;
        }
    };

    $provider::$mapped = $handlers;

    Switchboard::provider($provider::class);
}

function send(string $type = 'invoice.paid', string $id = 'evt_1'): TestResponse
{
    return test()->postJson('webhooks/acme', ['id' => $id, 'type' => $type, 'data' => ['amount' => 1000]]);
}

/** @return list<array{job: mixed, queue: string|null}> */
function queued(): array
{
    return collect(Queue::pushedJobs())->flatten(1)->all();
}

it('dispatches a queued job when a message is persisted', function () {
    Switchboard::provider(FakeProvider::class);

    Queue::fake();

    send()->assertNoContent();

    Queue::assertCount(1);
});

it('does not run the handler during the request that delivered the message', function () {
    Switchboard::provider(FakeProvider::class);

    Queue::fake();

    send()->assertNoContent();

    expect(RecordingHandler::$calls)->toBe([])
        ->and(InboxMessage::query()->sole()->processed_at)->toBeNull();
});

it('dispatches nothing for a duplicate delivery', function () {
    Switchboard::provider(FakeProvider::class);

    Queue::fake();

    send()->assertNoContent();
    send()->assertNoContent();

    Queue::assertCount(1);
});

it('takes the queue connection and queue name from configuration', function () {
    Switchboard::provider(FakeProvider::class);

    config(['switchboard.queue.connection' => 'redis', 'switchboard.queue.name' => 'webhooks']);

    Queue::fake();

    send()->assertNoContent();

    $job = queued()[0]['job'];

    expect($job->queue)->toBe('webhooks')
        ->and($job->connection)->toBe('redis');
});

it('runs the handler mapped to the event type', function () {
    Switchboard::provider(FakeProvider::class);

    send('invoice.paid', 'evt_1')->assertNoContent();

    expect(RecordingHandler::$calls)->toBe(['invoice.paid:evt_1']);
});

it('matches event types exactly, so ones a naive derivation would collide stay apart', function () {
    $first = new class {
        public function __invoke(InboxMessage $message): void
        {
            RecordingHandler::$calls[] = "first:{$message->event_type}";
        }
    };

    providerHandling([
        'customer.subscription.created' => RecordingHandler::class,
        'customer.subscriptionCreated' => $first::class,
    ]);

    send('customer.subscription.created', 'evt_1')->assertNoContent();
    send('customer.subscriptionCreated', 'evt_2')->assertNoContent();

    expect(RecordingHandler::$calls)->toBe([
        'customer.subscription.created:evt_1',
        'first:customer.subscriptionCreated',
    ]);
});

it('hands an unmapped event type to the provider rather than dropping it', function () {
    $provider = new class extends FakeProvider {
        public function unhandled(InboxMessage $message): void
        {
            RecordingHandler::$calls[] = "unhandled:{$message->event_type}";
        }
    };

    Switchboard::provider($provider::class);

    send('invoice.voided', 'evt_1')->assertNoContent();

    expect(RecordingHandler::$calls)->toBe(['unhandled:invoice.voided'])
        ->and(InboxMessage::query()->sole()->isProcessed())->toBeTrue();
});

it('makes an unhandled event type visible by default', function () {
    Log::spy();

    providerHandling([]);

    send('invoice.voided', 'evt_1')->assertNoContent();

    Log::shouldHaveReceived('warning')->once();
});

it('builds the handler through the container, so it can take dependencies', function () {
    $handler = new class (new ArrayObject()) {
        public function __construct(public ArrayObject $ledger) {}

        public function __invoke(InboxMessage $message): void
        {
            $this->ledger->append($message);
        }
    };

    $ledger = new ArrayObject();
    app()->bind($handler::class, fn() => new ($handler::class)($ledger));

    providerHandling(['invoice.paid' => $handler::class]);

    send('invoice.paid', 'evt_1')->assertNoContent();

    expect($ledger)->toHaveCount(1)
        ->and($ledger[0])->toBeInstanceOf(InboxMessage::class)
        ->and($ledger[0]->event_id)->toBe('evt_1')
        ->and($ledger[0]->data)->toBe(['amount' => 1000]);
});

it('marks a message processed when the handler returns', function () {
    Switchboard::provider(FakeProvider::class);

    $this->freezeTime();

    send()->assertNoContent();

    $message = InboxMessage::query()->sole();

    expect($message->processed_at?->timestamp)->toBe(now()->timestamp)
        ->and($message->isProcessed())->toBeTrue()
        ->and($message->isUnprocessed())->toBeFalse()
        ->and($message->failed_at)->toBeNull();
});

it('does not process a message twice', function () {
    Switchboard::provider(FakeProvider::class);

    send()->assertNoContent();

    $message = InboxMessage::query()->sole();

    ProcessInboxMessage::dispatch($message->id);

    expect(RecordingHandler::$calls)->toBe(['invoice.paid:evt_1']);
});
