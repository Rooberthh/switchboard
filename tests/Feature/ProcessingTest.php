<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Rooberthh\Switchboard\Inbox\Handler;
use Rooberthh\Switchboard\Models\InboxMessage;
use Rooberthh\Switchboard\Switchboard;
use Rooberthh\Switchboard\Tests\Fixtures\FakeDriver as AcmeDriver;
use Rooberthh\Switchboard\Tests\Fixtures\AcmeHandler;
use Illuminate\Testing\TestResponse;
use Rooberthh\Switchboard\Jobs\ProcessInboxMessage;

beforeEach(function () {
    AcmeHandler::$calls = [];

    Switchboard::extend('acme', new AcmeDriver());
    Switchboard::handledBy('acme', AcmeHandler::class);
    Switchboard::route('acme');
});

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
    Queue::fake();

    send()->assertNoContent();

    Queue::assertCount(1);
});

it('does not run the handler during the request that delivered the message', function () {
    Queue::fake();

    send()->assertNoContent();

    expect(AcmeHandler::$calls)->toBe([])
        ->and(InboxMessage::query()->sole()->processed_at)->toBeNull();
});

it('dispatches nothing for a duplicate delivery', function () {
    Queue::fake();

    send()->assertNoContent();
    send()->assertNoContent();

    Queue::assertCount(1);
});

it('takes the queue connection and queue name from configuration', function () {
    config(['switchboard.queue.connection' => 'redis', 'switchboard.queue.name' => 'webhooks']);

    Queue::fake();

    send()->assertNoContent();

    $job = queued()[0]['job'];

    expect($job->queue)->toBe('webhooks')
        ->and($job->connection)->toBe('redis');
});

it('routes an event type to the matching method on the handler', function () {
    send('invoice.paid', 'evt_1')->assertNoContent();

    expect(AcmeHandler::$calls)->toBe(['invoicePaid:evt_1']);
});

it('keeps event types apart that a naive derivation would collide', function () {
    send('customer.subscription.created', 'evt_1')->assertNoContent();
    send('customer.subscriptionCreated', 'evt_2')->assertNoContent();
    send('issue_comment.created', 'evt_3')->assertNoContent();

    expect(AcmeHandler::$calls)->toBe([
        'subscriptionCreated:evt_1',
        'legacySubscriptionCreated:evt_2',
        'issueCommentCreated:evt_3',
    ]);
});

it('reaches the fallback for an event type with no method', function () {
    send('invoice.voided', 'evt_1')->assertNoContent();

    expect(AcmeHandler::$calls)->toBe(['unhandled:invoice.voided']);
});

it('makes an unhandled event type visible rather than dropping it silently', function () {
    Log::spy();

    Switchboard::handledBy('acme', new class extends Handler {});

    send('invoice.voided', 'evt_1')->assertNoContent();

    Log::shouldHaveReceived('warning')->once();
});

it('lets an application replace the mapping entirely', function () {
    Switchboard::handledBy('acme', new class extends Handler {
        /** @var list<string> */
        public static array $calls = [];

        // Derivation rather than a registry, which is the application's
        // decision to make.
        protected function methodFor(InboxMessage $message): ?string
        {
            return lcfirst(str_replace(' ', '', ucwords(str_replace(['.', '_'], ' ', $message->event_type))));
        }

        public function invoicePaid(InboxMessage $message): void
        {
            AcmeHandler::$calls[] = "derived:{$message->event_id}";
        }
    });

    send('invoice.paid', 'evt_1')->assertNoContent();

    expect(AcmeHandler::$calls)->toBe(['derived:evt_1']);
});

it('hands the handler the inbox message itself', function () {
    $seen = null;

    Switchboard::handledBy('acme', new class ($seen) extends Handler {
        public function __construct(public mixed &$seen) {}

        protected function methodFor(InboxMessage $message): ?string
        {
            return 'record';
        }

        public function record(InboxMessage $message): void
        {
            $this->seen = $message;
        }
    });

    send('invoice.paid', 'evt_1')->assertNoContent();

    expect($seen)->toBeInstanceOf(InboxMessage::class)
        ->and($seen->event_id)->toBe('evt_1')
        ->and($seen->data)->toBe(['amount' => 1000]);
});

it('marks a message processed when the handler returns', function () {
    $this->freezeTime();

    send()->assertNoContent();

    $message = InboxMessage::query()->sole();

    expect($message->processed_at?->timestamp)->toBe(now()->timestamp)
        ->and($message->isProcessed())->toBeTrue()
        ->and($message->isUnprocessed())->toBeFalse()
        ->and($message->failed_at)->toBeNull();
});

it('does not process a message twice', function () {
    send()->assertNoContent();

    $message = InboxMessage::query()->sole();

    ProcessInboxMessage::dispatch($message->id);

    expect(AcmeHandler::$calls)->toBe(['invoicePaid:evt_1']);
});
