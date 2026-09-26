<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One try at sending a delivery: one request, or the refusal to make one, and
 * what came back.
 *
 * Append-only: written once, when the attempt ends, and never updated. A
 * replay adds attempts and removes none, so a delivery's attempts are its
 * whole history. The status is null when no response came back at all — a
 * timeout, a refused connection, or an address the SSRF guard would not
 * connect to. The response excerpt is the first kilobyte of the receiver's
 * body, where receivers put their reason for refusing.
 *
 * Public API, and deliberately not final: an application may extend it.
 *
 * @property int $id
 * @property int $delivery_id
 * @property int|null $status
 * @property string|null $error
 * @property int $duration_ms
 * @property string|null $response_excerpt
 * @property Carbon $created_at
 * @property-read Delivery $delivery
 */
class DeliveryAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('switchboard.tables.delivery_attempts', 'switchboard_delivery_attempts');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
