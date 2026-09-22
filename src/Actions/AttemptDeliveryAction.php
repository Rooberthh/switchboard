<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Actions;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Rooberthh\Switchboard\Contracts\Endpoints;
use Rooberthh\Switchboard\Events\OutboxDeliveryFailed;
use Rooberthh\Switchboard\Events\OutboxDeliverySucceeded;
use Rooberthh\Switchboard\Exceptions\UnsafeEndpoint;
use Rooberthh\Switchboard\Models\Delivery;
use Rooberthh\Switchboard\Outbox\EndpointData;
use Rooberthh\Switchboard\Support\RetrySchedule;
use Rooberthh\Switchboard\Support\SsrfGuard;
use Rooberthh\Switchboard\Support\StandardWebhooksSignature;

/**
 * Send one delivery once, and record what happened.
 *
 * The body is the message's stored body, byte for byte (ADR-0006). It is
 * signed with Standard Webhooks, the attempt's own timestamp and the
 * endpoint's current secret, and sent to the URL snapshotted on the delivery —
 * only once the SSRF guard has decided where that URL may connect.
 *
 * @internal
 */
final class AttemptDeliveryAction
{
    private const ERROR_LIMIT = 2000;

    public function __construct(
        private readonly Endpoints $endpoints,
        private readonly SsrfGuard $guard,
        private readonly RetrySchedule $schedule,
    ) {}

    public function execute(Delivery $delivery): void
    {
        $endpoint = $this->endpoints->find($delivery->endpoint_id);

        if ($endpoint === null) {
            $this->fail($delivery, null, 'The endpoint no longer exists or is disabled.');

            return;
        }

        $delivery->attempts++;

        try {
            $response = $this->send($delivery, $endpoint);
        } catch (UnsafeEndpoint|ConnectionException $e) {
            $this->retry($delivery, null, $e->getMessage());

            return;
        }

        if ($response->successful()) {
            $this->succeed($delivery, $response->status());

            return;
        }

        $this->retry(
            $delivery,
            $response->status(),
            "The endpoint answered {$response->status()}.",
            self::retryAfter($response),
        );
    }

    private function send(Delivery $delivery, EndpointData $endpoint): Response
    {
        // Before anything is signed or sent: where this may connect, pinned.
        $options = $this->guard->options($delivery->url);

        $message = $delivery->message;
        $timestamp = Carbon::now()->getTimestamp();

        return Http::timeout(self::timeout())
            ->withOptions($options)
            ->withUserAgent('Switchboard')
            ->withHeaders([
                'webhook-id' => $message->event_id,
                'webhook-timestamp' => (string) $timestamp,
                'webhook-signature' => StandardWebhooksSignature::header(
                    $message->event_id,
                    $timestamp,
                    $message->body,
                    StandardWebhooksSignature::key($endpoint->secret),
                ),
            ])
            ->withBody($message->body, 'application/json')
            ->post($delivery->url);
    }

    private function succeed(Delivery $delivery, int $status): void
    {
        $delivery->forceFill([
            'delivered_at' => Carbon::now(),
            'next_attempt_at' => null,
            'last_status' => $status,
            'last_error' => null,
        ])->save();

        event(new OutboxDeliverySucceeded($delivery));
    }

    /**
     * A failed attempt: try again on the schedule, or fail for good once it
     * is spent.
     *
     * @param Delivery $delivery
     * @param int|null $status
     * @param string $error
     * @param int|null $retryAfter
     */
    private function retry(Delivery $delivery, ?int $status, string $error, ?int $retryAfter = null): void
    {
        $next = $this->schedule->next($delivery->attempts, $retryAfter);

        if ($next === null) {
            $this->fail($delivery, $status, $error);

            return;
        }

        $delivery->forceFill([
            'next_attempt_at' => $next,
            'last_status' => $status,
            'last_error' => Str::limit($error, self::ERROR_LIMIT),
        ])->save();
    }

    private function fail(Delivery $delivery, ?int $status, string $error): void
    {
        $delivery->forceFill([
            'failed_at' => Carbon::now(),
            'next_attempt_at' => null,
            'last_status' => $status,
            'last_error' => Str::limit($error, self::ERROR_LIMIT),
        ])->save();

        event(new OutboxDeliveryFailed($delivery));
    }

    /**
     * The wait a throttled or overloaded receiver asked for, in seconds, as
     * a number or an HTTP date. Only 429, 502 and 504 are read: those are
     * the answers Standard Webhooks says to throttle on.
     *
     * @param Response $response
     */
    private static function retryAfter(Response $response): ?int
    {
        if (! in_array($response->status(), [429, 502, 504], true)) {
            return null;
        }

        $header = trim($response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $at = strtotime($header);

        return $at === false ? null : max(0, $at - Carbon::now()->getTimestamp());
    }

    private static function timeout(): int
    {
        return max(1, (int) config('switchboard.outbox.timeout', 15));
    }
}
