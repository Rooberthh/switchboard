<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * Every attempt is kept (docs/adr/0008-the-outbox-keeps-every-delivery-attempt.md).
 * The attempt count is the position in the current retry schedule, which a
 * replay starts over; last_status and last_error summarise how the latest
 * attempt, or the delivery itself, ended.
 *
 * Public API, and deliberately not final: an application may extend it.
 *
 * @property int $id
 * @property int $outbox_message_id
 * @property string $endpoint_id
 * @property string $url
 * @property int $attempt_count
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property int|null $last_status
 * @property string|null $last_error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read OutboxMessage $message
 * @property-read Collection<int, DeliveryAttempt> $attempts
 * @property-read DeliveryAttempt|null $latestAttempt
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
            'attempt_count' => 'integer',
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

    /**
     * Every attempt, replays included.
     *
     * @return HasMany<DeliveryAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    /**
     * @return HasOne<DeliveryAttempt, $this>
     */
    public function latestAttempt(): HasOne
    {
        return $this->hasOne(DeliveryAttempt::class)->latestOfMany();
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
