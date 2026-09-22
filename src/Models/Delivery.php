<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One endpoint's copy of one outbox message, and the record of the attempts
 * to deliver it.
 *
 * Lifecycle is timestamps: delivered, failed, or neither — pending, with a
 * next attempt time the relay queues it at. The URL is a snapshot taken when
 * the relay created it, so a delivery's destination is fixed for its life;
 * the secret is not, and each attempt signs with the endpoint's current one.
 *
 * Public API, and deliberately not final: an application may extend it.
 *
 * @property int $id
 * @property int $outbox_message_id
 * @property string $endpoint_id
 * @property string $url
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property int|null $last_status
 * @property string|null $last_error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read OutboxMessage $message
 */
class Delivery extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('switchboard.tables.deliveries', 'switchboard_deliveries');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'last_status' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<OutboxMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(OutboxMessage::class, 'outbox_message_id');
    }

    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }

    public function isFailed(): bool
    {
        return $this->failed_at !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isDelivered() && ! $this->isFailed();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('delivered_at')->whereNull('failed_at');
    }

    /**
     * Pending, and its next attempt has come.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->pending()->where('next_attempt_at', '<=', Carbon::now());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDelivered(Builder $query): Builder
    {
        return $query->whereNotNull('delivered_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereNotNull('failed_at');
    }
}
