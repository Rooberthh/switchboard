<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An inbound webhook, persisted on arrival before anything acts on it.
 *
 * A normalized record of what the event means, not a capture of the request:
 * no raw body, no headers. See docs/adr/0003-inbox-messages-are-normalized-records.md.
 *
 * Lifecycle is timestamps, not a status enum. A message with neither
 * processed_at nor failed_at is unprocessed — including one a worker is
 * handling right now, since there is no in-flight state.
 *
 * Public API, and deliberately not final: an application may extend it.
 *
 * @property int $id
 * @property string $provider
 * @property string $event_id
 * @property string $event_type
 * @property string|null $subject
 * @property array<string, mixed> $data
 * @property Carbon $occurred_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $failed_at
 * @property string|null $last_error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class InboxMessage extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('switchboard.tables.inbox_messages', 'switchboard_inbox_messages');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    public function isFailed(): bool
    {
        return $this->failed_at !== null;
    }

    /**
     * Neither succeeded nor failed yet, whether or not a worker has it.
     */
    public function isUnprocessed(): bool
    {
        return ! $this->isProcessed() && ! $this->isFailed();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeProcessed(Builder $query): Builder
    {
        return $query->whereNotNull('processed_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereNotNull('failed_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->whereNull('processed_at')->whereNull('failed_at');
    }

    /**
     * @param  Builder<static>  $query
     * @param string $provider
     * @return Builder<static>
     */
    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }
}
