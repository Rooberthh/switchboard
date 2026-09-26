<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Actions;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Rooberthh\HttpTools\Enums\StatusCode;
use Rooberthh\Switchboard\Contracts\Endpoints;
use Rooberthh\Switchboard\Events\EndpointDisabled;
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
 * Every attempt is written as its own row, in the same transaction as the
 * delivery's new state (ADR-0008). An endpoint that is gone ends the delivery
 * without an attempt: nothing is sent, and no retry is spent.
 *
 * @phpstan-type Attempt array{status: int|null, error: string|null, duration_ms: int, response_excerpt: string|null}
 *
 * @internal
 */
final class AttemptDeliveryAction
{
    private const ERROR_LIMIT = 2000;

    /**
     * Bytes of the receiver's answer kept on the attempt.
     */
    private const EXCERPT_LIMIT = 1024;

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

        $delivery->attempt_count++;

        $started = hrtime(true);

        try {
            $response = $this->send($delivery, $endpoint);
        } catch (UnsafeEndpoint|ConnectionException $e) {
            $this->retry($delivery, self::attempt(self::since($started), null, $e->getMessage()));

            return;
        }

        $duration = self::since($started);
        $status = $response->status();
        $excerpt = self::excerpt($response);

        if ($response->successful()) {
            $this->succeed($delivery, self::attempt($duration, $status, null, $excerpt));

            return;
        }

        // The receiver says the endpoint is gone: stop sending to it at all,
        // rather than spending three days of retries finding out.
        if ($status === StatusCode::GONE->value) {
            $this->endpoints->disable($endpoint->id);

            event(new EndpointDisabled($endpoint));

            $error = 'The endpoint answered 410 Gone and has been disabled.';

            $this->fail($delivery, $status, $error, self::attempt($duration, $status, $error, $excerpt));

            return;
        }

        $this->retry(
            $delivery,
            self::attempt($duration, $status, "The endpoint answered {$status}.", $excerpt),
            self::retryAfter($response),
        );
    }

    private function send(Delivery $delivery, EndpointData $endpoint): Response
    {
        // Before anything is signed or sent: where this may connect, pinned.
        // Never add Guzzle's "stream" option here: streamed requests go
        // through PHP's stream handler, which ignores the curl pin.
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

    /**
     * @param  Attempt  $attempt
     * @param Delivery $delivery
     */
    private function succeed(Delivery $delivery, array $attempt): void
    {
        $this->record($delivery, [
            'delivered_at' => Carbon::now(),
            'next_attempt_at' => null,
            'last_status' => $attempt['status'],
            'last_error' => null,
        ], $attempt);

        event(new OutboxDeliverySucceeded($delivery));
    }

    /**
     * A failed attempt: try again on the schedule, or fail for good once it
     * is spent.
     *
     * @param  Attempt  $attempt
     * @param Delivery $delivery
     * @param ?int $retryAfter
     */
    private function retry(Delivery $delivery, array $attempt, ?int $retryAfter = null): void
    {
        $status = $attempt['status'];
        $error = (string) $attempt['error'];

        $next = $this->schedule->next($delivery->attempt_count, $retryAfter);

        if ($next === null) {
            $this->fail($delivery, $status, $error, $attempt);

            return;
        }

        $this->record($delivery, [
            'next_attempt_at' => $next,
            'last_status' => $status,
            'last_error' => $error,
        ], $attempt);
    }

    /**
     * @param  Attempt|null  $attempt  Null when the delivery ends without one.
     * @param Delivery $delivery
     * @param ?int $status
     * @param string $error
     */
    private function fail(Delivery $delivery, ?int $status, string $error, ?array $attempt = null): void
    {
        $this->record($delivery, [
            'failed_at' => Carbon::now(),
            'next_attempt_at' => null,
            'last_status' => $status,
            'last_error' => Str::limit($error, self::ERROR_LIMIT),
        ], $attempt);

        event(new OutboxDeliveryFailed($delivery));
    }

    /**
     * The delivery's new state and its attempt, together, so the summary on
     * the delivery never disagrees with the attempts behind it.
     *
     * @param  array<string, mixed>  $changes
     * @param  Attempt|null  $attempt
     * @param Delivery $delivery
     */
    private function record(Delivery $delivery, array $changes, ?array $attempt): void
    {
        DB::transaction(function () use ($delivery, $changes, $attempt): void {
            $delivery->forceFill($changes)->save();

            if ($attempt !== null) {
                $delivery->attempts()->create($attempt);
            }
        });
    }

    /**
     * @param  int  $duration  Milliseconds the attempt took.
     * @param ?int $status
     * @param ?string $error
     * @param ?string $excerpt
     * @return Attempt
     */
    private static function attempt(int $duration, ?int $status, ?string $error, ?string $excerpt = null): array
    {
        return [
            'status' => $status,
            'error' => $error === null ? null : Str::limit($error, self::ERROR_LIMIT),
            'duration_ms' => $duration,
            'response_excerpt' => $excerpt,
        ];
    }

    /**
     * Milliseconds since an hrtime() reading.
     * @param int $started
     */
    private static function since(int $started): int
    {
        return intdiv(hrtime(true) - $started, 1_000_000);
    }

    /**
     * The start of the receiver's answer, where it says why it refused.
     *
     * Read from the body stream, so a large answer is never pulled into
     * memory whole. Scrubbed to valid UTF-8 without NUL bytes, which some
     * databases refuse in a text column: an attempt that cannot be written
     * would leave the delivery retrying forever.
     * @param Response $response
     */
    private static function excerpt(Response $response): ?string
    {
        $body = $response->toPsrResponse()->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $excerpt = str_replace("\0", '', mb_scrub(Utils::copyToString($body, self::EXCERPT_LIMIT), 'UTF-8'));

        return $excerpt === '' ? null : $excerpt;
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
        $throttled = [StatusCode::TOO_MANY_REQUESTS, StatusCode::BAD_GATEWAY, StatusCode::GATEWAY_TIMEOUT];

        if (! in_array(StatusCode::tryFrom($response->status()), $throttled, true)) {
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
