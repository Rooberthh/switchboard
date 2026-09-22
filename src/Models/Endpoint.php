<?php

declare(strict_types=1);

namespace Rooberthh\Switchboard\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Rooberthh\Switchboard\Outbox\EndpointData;

/**
 * A URL that receives outbound messages, together with the exact event types
 * it subscribes to. The default storage behind the Endpoints contract.
 *
 * Created without a secret, it generates one in the Standard Webhooks format
 * — "whsec_" and 32 random bytes, base64 — and stores it encrypted. Show it to
 * the endpoint's owner once; it is never logged or serialized.
 *
 * Public API, and deliberately not final: an application may extend it.
 *
 * @property int $id
 * @property string $url
 * @property list<string> $event_types
 * @property string $secret
 * @property Carbon|null $disabled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Endpoint extends Model
{
    protected $guarded = [];

    protected $hidden = ['secret'];

    public function getTable(): string
    {
        return (string) config('switchboard.tables.endpoints', 'switchboard_endpoints');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_types' => 'array',
            'secret' => 'encrypted',
            'disabled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Endpoint $endpoint): void {
            if (($endpoint->attributes['secret'] ?? null) === null) {
                $endpoint->secret = self::generateSecret();
            }
        });
    }

    /**
     * A new Standard Webhooks secret: "whsec_" and 32 random bytes, base64.
     */
    public static function generateSecret(): string
    {
        return 'whsec_' . base64_encode(random_bytes(32));
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('disabled_at');
    }

    /**
     * Active endpoints subscribed to exactly this event type.
     *
     * @param  Builder<static>  $query
     * @param  string  $eventType
     * @return Builder<static>
     */
    public function scopeSubscribedTo(Builder $query, string $eventType): Builder
    {
        return $query->active()->whereJsonContains('event_types', $eventType);
    }

    public function toEndpointData(): EndpointData
    {
        return new EndpointData(id: (string) $this->getKey(), url: $this->url, secret: $this->secret);
    }
}
