<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Support\Inbox;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rooberthh\Switchboard\DataObjects\Inbox\InboxMessageData;
use Rooberthh\Switchboard\Events\InboxMessageFailed;
use Rooberthh\Switchboard\Events\InboxMessageProcessed;
use Rooberthh\Switchboard\Events\InboxMessageReceived;
use Rooberthh\Switchboard\Jobs\Inbox\ProcessInboxMessage;
use Rooberthh\Switchboard\Models\InboxMessage;
use Throwable;

/**
 * Every change to an inbox message's state, in one place.
 *
 * Ingesting stores a message once per event ID and queues it. Its job ends it
 * processed or failed; the relay queues one whose job was never queued
 * (ADR-0004), and replay returns a failed one to unprocessed.
 *
 * Every transition after ingest is a claim: one conditional update that names
 * the state it moves from (ADR-0009). A claim that matches no row did not
 * happen, so it writes, announces, queues and throws nothing, and says so by
 * returning false. That is what lets the overlaps at-least-once processing
 * produces — two jobs for one message, two relay sweeps — settle on whichever
 * came first: processed and failed are final until replay, and each is
 * announced once.
 *
 * @internal
 */
final class InboxMessageLifecycle
{
    /**
     * Enough to diagnose a failure without reproducing it.
     */
    private const ERROR_LIMIT = 2000;

    /**
     * Persist a verified message and queue it, once per event ID.
     *
     * Idempotent on the provider and event ID: a repeat delivery returns the
     * message already stored, untouched, and queues and announces nothing — the
     * first delivery wins. Only a message this call actually inserted is queued
     * and announced, both after commit, so nothing acts on a message that the
     * surrounding transaction then rolled back.
     *
     * Verification is not part of it. Whoever calls this has already decided
     * the message is authentic.
     * @param InboxMessageData $data
     */
    public function ingest(InboxMessageData $data): InboxMessage
    {
        $message = InboxMessages::createOrFirst(
            [
                'provider' => $data->provider,
                'event_id' => $data->eventId,
            ],
            [
                'event_type' => $data->eventType,
                'subject' => $data->subject,
                'data' => $data->data,
                'occurred_at' => $data->occurredAt ?? now(),
            ],
        );

        if ($message->wasRecentlyCreated) {
            ProcessInboxMessage::dispatch($message->id)->afterCommit();

            event(new InboxMessageReceived($message));
        }

        return $message;
    }

    /**
     * Record that a handler succeeded, and announce it.
     *
     * Distinct from processing itself, which is the handler running on the
     * queue: the handler does the work, this is the record of it. A message
     * that is already processed or failed stays as it is, because whichever
     * outcome was recorded first stands.
     *
     * @return bool Whether this call marked it; false when it was already processed or failed.
     * @param InboxMessage $message
     */
    public function processed(InboxMessage $message): bool
    {
        if (! $this->claim($message, self::unfinished(...), ['processed_at' => Carbon::now()])) {
            return false;
        }

        event(new InboxMessageProcessed($message));

        return true;
    }

    /**
     * Record that a message has failed for good, and why, and announce it.
     *
     * Owns the shape of last_error as well as the write: enough to diagnose
     * the failure without reproducing it, and bounded, so one pathological
     * exception cannot fill the column. A message that is already processed
     * or failed stays as it is, so a second failure keeps the first error.
     *
     * @return bool Whether this call marked it; false when it was already processed or failed.
     * @param InboxMessage $message
     * @param Throwable $exception
     */
    public function failed(InboxMessage $message, Throwable $exception): bool
    {
        $claimed = $this->claim($message, self::unfinished(...), [
            'failed_at' => Carbon::now(),
            'last_error' => self::describe($exception),
        ]);

        if (! $claimed) {
            return false;
        }

        event(new InboxMessageFailed($message, $exception));

        return true;
    }

    /**
     * Relay one stranded message: mark it, queue it, and put the mark back if
     * the queue will not take it.
     *
     * Marked before dispatching, so a sweep that dies partway cannot come back
     * and relay the same message a second time, and marked by a claim, so two
     * overlapping sweeps cannot both relay it. Unmarked if the dispatch throws,
     * so a queue that is still part of the outage leaves the message exactly
     * as it was for the next sweep.
     *
     * relayed_at is bookkeeping, not a lifecycle state: a relayed message is
     * still unprocessed. Nothing is announced, because nothing has happened to
     * the message that an application did not already know about.
     *
     * @return bool Whether this call relayed it; false when it was already relayed.
     * @param InboxMessage $message
     */
    public function relay(InboxMessage $message): bool
    {
        $unrelayed = fn(Builder $query): Builder => $query->whereNull('relayed_at');

        if (! $this->claim($message, $unrelayed, ['relayed_at' => Carbon::now()])) {
            return false;
        }

        try {
            ProcessInboxMessage::dispatch($message->id);
        } catch (Throwable $e) {
            $this->put($message, ['relayed_at' => null]);

            throw $e;
        }

        return true;
    }

    /**
     * Return one failed message to unprocessed and queue it again.
     *
     * Only a failed message is eligible: re-running one that succeeded would
     * repeat side effects the application already performed. Replay is re-run,
     * never re-verify (ADR-0003).
     *
     * If the queue will not take the job, the failure is put back, diagnosis
     * and all, rather than leaving the message unprocessed with no job to
     * process it and its diagnosis erased.
     *
     * @return bool Whether this call replayed it; false when it had not failed.
     * @param InboxMessage $message
     */
    public function replay(InboxMessage $message): bool
    {
        $failure = [
            'failed_at' => $message->failed_at,
            'last_error' => $message->last_error,
        ];

        $failed = fn(Builder $query): Builder => $query->whereNotNull('failed_at');

        if (! $this->claim($message, $failed, ['failed_at' => null, 'last_error' => null])) {
            return false;
        }

        try {
            ProcessInboxMessage::dispatch($message->id);
        } catch (Throwable $e) {
            $this->put($message, $failure);

            throw $e;
        }

        return true;
    }

    /**
     * Move the message by one conditional update on the stored row, never a
     * check of the copy in memory, and bring that copy along only when the
     * update won, so listeners and callers see the new state.
     *
     * @param  Closure(Builder<InboxMessage>): Builder<InboxMessage>  $from  The state it moves from.
     * @param  array<string, mixed>  $changes
     * @param InboxMessage $message
     */
    private function claim(InboxMessage $message, Closure $from, array $changes): bool
    {
        $claimed = $from(InboxMessages::query()->whereKey($message->getKey()))->update($changes);

        if ($claimed === 0) {
            return false;
        }

        $message->forceFill($changes)->syncOriginal();

        return true;
    }

    /**
     * Undo a claim whose dispatch failed.
     *
     * @param  array<string, mixed>  $values
     * @param InboxMessage $message
     */
    private function put(InboxMessage $message, array $values): void
    {
        InboxMessages::query()->whereKey($message->getKey())->update($values);

        $message->forceFill($values)->syncOriginal();
    }

    /**
     * Neither processed nor failed: the state both outcomes move from.
     *
     * @param  Builder<InboxMessage>  $query
     * @return Builder<InboxMessage>
     */
    private static function unfinished(Builder $query): Builder
    {
        return $query->whereNull('processed_at')->whereNull('failed_at');
    }

    private static function describe(Throwable $exception): string
    {
        return Str::limit(sprintf(
            '%s: %s in %s:%d',
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
        ), self::ERROR_LIMIT);
    }
}
