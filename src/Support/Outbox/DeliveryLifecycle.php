<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Support\Outbox;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Rooberthh\HttpTools\Enums\StatusCode;
use Rooberthh\Switchboard\Actions\Outbox\AttemptDeliveryAction;
use Rooberthh\Switchboard\Contracts\Endpoints;
use Rooberthh\Switchboard\DataObjects\Outbox\EndpointData;
use Rooberthh\Switchboard\Events\EndpointDisabled;
use Rooberthh\Switchboard\Events\OutboxDeliveryFailed;
use Rooberthh\Switchboard\Events\OutboxDeliverySucceeded;
use Rooberthh\Switchboard\Exceptions\IllegalTransition;
use Rooberthh\Switchboard\Jobs\Outbox\AttemptDelivery;
use Rooberthh\Switchboard\Models\Delivery;
use Rooberthh\Switchboard\Models\OutboxMessage;
use Throwable;

/**
 * Every change to a delivery's state, in one place.
 *
 * The relay creates a delivery and queues it whenever it is due, which is the
 * only way one reaches its endpoint (ADR-0007). An attempt ends it delivered,
 * ends it failed, or schedules the next; replay returns a failed one to
 * pending. Every attempt is written as its own row in the same transaction as
 * the delivery's new state, so the summary on the delivery never disagrees
 * with the attempts behind it (ADR-0008).
 *
 * @internal
 */
final class DeliveryLifecycle
{
    private const ERROR_LIMIT = 2000;

    /**
     * Seconds a queued attempt may wait for a worker, on top of its timeout.
     */
    private const LEASE_MARGIN = 60;

    public function __construct(
        private readonly Endpoints $endpoints,
        private readonly RetrySchedule $schedule,
    ) {}

    /**
     * Turn one committed message into one delivery per endpoint subscribed to
     * its event type, due now.
     *
     * The claim and the deliveries commit together: relayed_at is set by a
     * conditional update first, so of two overlapping relays only one creates
     * the deliveries, and a crash partway leaves the message unrelayed for the
     * next run rather than half relayed. The unique index on message and
     * endpoint is the second guard. A message nobody subscribes to is relayed
     * with none — it is still the record of what happened.
     *
     * @return int The deliveries created; 0 when another relay got there first.
     * @param OutboxMessage $message
     */
    public function relay(OutboxMessage $message): int
    {
        return DB::transaction(function () use ($message): int {
            $now = Carbon::now();

            $claimed = OutboxMessage::query()
                ->whereKey($message->getKey())
                ->whereNull('relayed_at')
                ->update(['relayed_at' => $now]);

            if ($claimed === 0) {
                return 0;
            }

            $created = 0;

            foreach ($this->endpoints->subscribedTo($message->event_type) as $endpoint) {
                $created += Delivery::query()->insertOrIgnore([
                    'outbox_message_id' => $message->getKey(),
                    'endpoint_id' => $endpoint->id,
                    'url' => $endpoint->url,
                    'attempt_count' => 0,
                    'next_attempt_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $created;
        });
    }

    /**
     * Queue one due delivery, leasing it so the next relay run leaves it alone.
     *
     * The lease pushes its next attempt time forward before the job is queued.
     * If the job is lost, the lease runs out and a later relay queues it again
     * — which is what makes delivery at-least-once. If the queue refuses the
     * job outright, the lease is put back, so a queue that is part of an
     * outage leaves the delivery exactly as due as it was.
     *
     * @return bool Whether this call queued it; false when it was no longer due.
     * @param Delivery $delivery
     */
    public function queue(Delivery $delivery): bool
    {
        $due = $delivery->next_attempt_at;

        $leased = Delivery::query()
            ->whereKey($delivery->getKey())
            ->due()
            ->update(['next_attempt_at' => Carbon::now()->addSeconds(self::lease())]);

        if ($leased === 0) {
            return false;
        }

        try {
            AttemptDelivery::dispatch($delivery->getKey());
        } catch (Throwable $e) {
            Delivery::query()->whereKey($delivery->getKey())->update(['next_attempt_at' => $due]);

            throw $e;
        }

        return true;
    }

    /**
     * Record one attempt and what it means for the delivery: delivered on a
     * 2xx; ended on a 410, with the endpoint disabled so nothing more is sent
     * to it; otherwise tried again on the schedule — after the wait a
     * throttled receiver asked for, on 429, 502 and 504, the answers Standard
     * Webhooks says to throttle on — until the schedule is spent.
     * @param Delivery $delivery
     * @param EndpointData $endpoint
     * @param Attempt $attempt
     */
    public function attempted(Delivery $delivery, EndpointData $endpoint, Attempt $attempt): void
    {
        $delivery->attempt_count++;

        $status = $attempt->status;

        if ($status !== null && $status >= 200 && $status < 300) {
            $this->record($delivery, [
                'delivered_at' => Carbon::now(),
                'next_attempt_at' => null,
                'last_status' => $status,
                'last_error' => null,
            ], $attempt, null);

            event(new OutboxDeliverySucceeded($delivery));

            return;
        }

        // The receiver says the endpoint is gone: stop sending to it at all,
        // rather than spending three days of retries finding out. Disabled in
        // the same transaction, so with the database storage an endpoint is
        // never disabled without the failed delivery that says why.
        if ($status === StatusCode::GONE->value) {
            DB::transaction(function () use ($delivery, $endpoint, $attempt, $status): void {
                $this->endpoints->disable($endpoint->id);

                event(new EndpointDisabled($endpoint));

                $this->fail($delivery, $status, 'The endpoint answered 410 Gone and has been disabled.', $attempt);
            });

            return;
        }

        $error = $status === null ? (string) $attempt->error : "The endpoint answered {$status}.";

        $next = $this->schedule->next($delivery->attempt_count, self::throttled($status) ? $attempt->retryAfter : null);

        if ($next === null) {
            $this->fail($delivery, $status, $error, $attempt);

            return;
        }

        $this->record($delivery, [
            'next_attempt_at' => $next,
            'last_status' => $status,
            'last_error' => Str::limit($error, self::ERROR_LIMIT),
        ], $attempt, $error);
    }

    /**
     * End a delivery whose endpoint no longer exists or is disabled. Nothing
     * was sent, so no attempt is recorded and no retry is spent.
     * @param Delivery $delivery
     */
    public function endpointGone(Delivery $delivery): void
    {
        $this->fail($delivery, null, 'The endpoint no longer exists or is disabled.');
    }

    /**
     * Return one failed delivery to pending, due now, for the relay to send.
     *
     * Only a failed delivery is eligible: sending a delivered one again would
     * repeat what the receiver already has. It starts the schedule over, and it
     * sends the same stored body to the same snapshotted URL — replay is re-run
     * of exactly what was sent. The attempts already made are kept; the
     * replayed ones are added after them.
     *
     * @throws IllegalTransition when the delivery has not failed
     * @param Delivery $delivery
     */
    public function replay(Delivery $delivery): void
    {
        if (! $delivery->isFailed()) {
            throw IllegalTransition::deliveryNotFailed($delivery);
        }

        $delivery->forceFill([
            'failed_at' => null,
            'attempt_count' => 0,
            'next_attempt_at' => Carbon::now(),
        ])->save();
    }

    /**
     * @param  Attempt|null  $attempt  Null when the delivery ends without one.
     * @param Delivery $delivery
     * @param ?int $status
     * @param string $error
     */
    private function fail(Delivery $delivery, ?int $status, string $error, ?Attempt $attempt = null): void
    {
        $this->record($delivery, [
            'failed_at' => Carbon::now(),
            'next_attempt_at' => null,
            'last_status' => $status,
            'last_error' => Str::limit($error, self::ERROR_LIMIT),
        ], $attempt, $error);

        event(new OutboxDeliveryFailed($delivery));
    }

    /**
     * The delivery's new state and its attempt, together, so the summary on
     * the delivery never disagrees with the attempts behind it.
     *
     * @param  array<string, mixed>  $changes
     * @param  Attempt|null  $attempt  Null when the delivery ends without one.
     * @param  string|null  $error  How the attempt is described: null when it succeeded.
     * @param Delivery $delivery
     */
    private function record(Delivery $delivery, array $changes, ?Attempt $attempt, ?string $error): void
    {
        DB::transaction(function () use ($delivery, $changes, $attempt, $error): void {
            $delivery->forceFill($changes)->save();

            if ($attempt !== null) {
                $delivery->attempts()->create([
                    'status' => $attempt->status,
                    'error' => $error === null ? null : Str::limit($error, self::ERROR_LIMIT),
                    'duration_ms' => $attempt->durationMs,
                    'response_excerpt' => $attempt->excerpt,
                ]);
            }
        });
    }

    private static function throttled(?int $status): bool
    {
        return in_array(
            StatusCode::tryFrom((int) $status),
            [StatusCode::TOO_MANY_REQUESTS, StatusCode::BAD_GATEWAY, StatusCode::GATEWAY_TIMEOUT],
            true,
        );
    }

    /**
     * Long enough for a queued attempt to be picked up and finish, request
     * timeout included; after it, the relay presumes the job lost. A lease
     * shorter than the timeout would queue a second attempt while the first
     * is still waiting on the endpoint, so it never is.
     */
    private static function lease(): int
    {
        return max(
            (int) config('switchboard.outbox.lease', 300),
            AttemptDeliveryAction::timeout() + self::LEASE_MARGIN,
        );
    }
}
