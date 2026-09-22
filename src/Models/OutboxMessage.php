<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An outbound webhook: an event type and its payload, persisted when it is
 * emitted, inside the caller's transaction if there is one.
 *
 * It records what happened once, independently of who it goes to, and it has
 * no lifecycle of its own — its deliveries do. The body is the envelope it is
 * sent in, rendered once at emit so every endpoint and every attempt gets the
 * same bytes. See docs/adr/0006-the-outbox-stores-the-rendered-body.md.
 *
 * Public API, and deliberately not final: an application may extend it.
 *
 * @property int $id
 * @property string $event_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property string $body
 * @property string|null $idempotency_key
 * @property Carbon|null $relayed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OutboxMessage extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('switchboard.tables.outbox_messages', 'switchboard_outbox_messages');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'relayed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Delivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'outbox_message_id');
    }

    /**
     * Committed, and not yet turned into deliveries by the relay.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnrelayed(Builder $query): Builder
    {
        return $query->whereNull('relayed_at');
    }
}
