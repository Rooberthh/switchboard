<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Actions\Outbox;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Rooberthh\Switchboard\Contracts\Endpoints;
use Rooberthh\Switchboard\DataObjects\Outbox\EndpointData;
use Rooberthh\Switchboard\Exceptions\InvalidProviderSecret;
use Rooberthh\Switchboard\Exceptions\UnsafeEndpoint;
use Rooberthh\Switchboard\Models\Delivery;
use Rooberthh\Switchboard\Support\Outbox\Attempt;
use Rooberthh\Switchboard\Support\Outbox\DeliveryLifecycle;
use Rooberthh\Switchboard\Support\Outbox\SsrfGuard;
use Rooberthh\Switchboard\Support\StandardWebhooksSignature;
use Throwable;

/**
 * Send one delivery once, and hand what happened to the delivery lifecycle.
 *
 * The body is the message's stored body, byte for byte (ADR-0006). It is
 * signed with Standard Webhooks, the attempt's own timestamp and the
 * endpoint's current secret, and sent to the URL snapshotted on the delivery —
 * only once the SSRF guard has decided where that URL may connect.
 *
 * An endpoint that is gone ends the delivery without an attempt: nothing is
 * sent, and no retry is spent.
 *
 * @internal
 */
final class AttemptDeliveryAction
{
    /**
     * Bytes of the receiver's answer kept on the attempt.
     */
    private const EXCERPT_LIMIT = 1024;

    public function __construct(
        private readonly Endpoints $endpoints,
        private readonly SsrfGuard $guard,
        private readonly DeliveryLifecycle $lifecycle,
    ) {}

    public function execute(Delivery $delivery): void
    {
        $endpoint = $this->endpoints->find($delivery->endpoint_id);

        if ($endpoint === null) {
            $this->lifecycle->endpointGone($delivery);

            return;
        }

        $this->lifecycle->attempted($delivery, $endpoint, $this->send($delivery, $endpoint));
    }

    /**
     * One request, or the refusal to make one, and what came back. Every
     * failure to get an answer becomes an attempt rather than escaping, so
     * the delivery keeps its schedule instead of dying with the job and being
     * leased again forever.
     * @param Delivery $delivery
     * @param EndpointData $endpoint
     */
    private function send(Delivery $delivery, EndpointData $endpoint): Attempt
    {
        $started = hrtime(true);

        try {
            $response = $this->request($delivery, $endpoint);
        } catch (UnsafeEndpoint|ConnectionException $e) {
            return new Attempt(status: null, error: $e->getMessage(), durationMs: self::since($started));
        } catch (Throwable $e) {
            // Anything else is a fault on our side, such as a secret that is
            // not base64: reported, so someone sees it.
            report($e);

            return new Attempt(status: null, error: $e->getMessage(), durationMs: self::since($started));
        }

        $duration = self::since($started);

        return new Attempt(
            status: $response->status(),
            error: null,
            durationMs: $duration,
            excerpt: self::excerpt($response),
            retryAfter: self::retryAfter($response),
        );
    }

    private function request(Delivery $delivery, EndpointData $endpoint): Response
    {
        // Before anything is signed or sent: where this may connect, pinned.
        // Never add Guzzle's "stream" option here: streamed requests go
        // through PHP's stream handler, which ignores the curl pin.
        $options = $this->guard->options($delivery->url);

        // An empty key signs, but anyone could produce that signature.
        if ($endpoint->secret === '') {
            throw InvalidProviderSecret::empty();
        }

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
     * The wait the receiver asked for, in seconds, as a number or an HTTP
     * date. Whether to honour it is the lifecycle's call.
     *
     * @param Response $response
     */
    private static function retryAfter(Response $response): ?int
    {
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
