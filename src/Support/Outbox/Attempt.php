<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Support\Outbox;

/**
 * One try at sending a delivery: one request, or the refusal to make one, and
 * what came back. Facts only — what they mean for the delivery is the
 * lifecycle's to decide.
 *
 * @internal
 */
final readonly class Attempt
{
    /**
     * @param  int|null  $status  What the receiver answered; null when no answer came back.
     * @param  string|null  $error  Why no answer came back; null when one did.
     * @param  int  $durationMs  How long the attempt took.
     * @param  string|null  $excerpt  The start of the receiver's answer.
     * @param  int|null  $retryAfter  Seconds the receiver asked to wait, whatever its status.
     */
    public function __construct(
        public ?int $status,
        public ?string $error,
        public int $durationMs,
        public ?string $excerpt = null,
        public ?int $retryAfter = null,
    ) {}
}
