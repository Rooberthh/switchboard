<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Actions;

use Illuminate\Support\Str;
use Rooberthh\Switchboard\Events\InboxMessageFailed;
use Rooberthh\Switchboard\Exceptions\IllegalTransition;
use Rooberthh\Switchboard\Models\InboxMessage;
use Throwable;

/**
 * Record that a message has failed for good, and why.
 *
 * Owns the shape of last_error as well as the write: enough to diagnose the
 * failure without reproducing it, and bounded, so one pathological exception
 * cannot fill the column.
 *
 * Tolerant of a repeat for the same reason {@see ProcessInboxMessageAction} is — two jobs
 * for one message can both spend their attempts — and the later error wins,
 * because it is the more recent diagnosis.
 *
 * @internal
 */
final class FailInboxMessageAction
{
    /**
     * Enough to diagnose the failure without reproducing it.
     */
    private const LIMIT = 2000;

    /**
     * @throws IllegalTransition when the message has already been processed
     * @param InboxMessage $message
     * @param Throwable $exception
     */
    public function execute(InboxMessage $message, Throwable $exception): void
    {
        if ($message->isProcessed()) {
            throw IllegalTransition::alreadyProcessed($message);
        }

        $message->forceFill([
            'failed_at' => now(),
            'last_error' => self::describe($exception),
        ])->save();

        event(new InboxMessageFailed($message, $exception));
    }

    private static function describe(Throwable $exception): string
    {
        return Str::limit(sprintf(
            '%s: %s in %s:%d',
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
        ), self::LIMIT);
    }
}
