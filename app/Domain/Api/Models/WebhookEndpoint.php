<?php

namespace App\Domain\Api\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An address that receives signed event notifications (HMAC-SHA256 with the secret).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $url
 * @property string $secret
 * @property list<string> $events
 * @property bool $is_active
 * @property int $consecutive_failures
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 */
class WebhookEndpoint extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;

    public const EVENTS = ['vehicle.listed', 'vehicle.reserved', 'vehicle.sold', 'vehicle.unlisted', 'enquiry.received', 'invoice.paid'];

    /** After this many failed deliveries in a row the endpoint is switched off. */
    public const MAX_FAILURES = 20;

    protected $fillable = ['tenant_id', 'url', 'secret', 'events', 'is_active'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    public function listensTo(string $event): bool
    {
        return $this->is_active && in_array($event, $this->events, true);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }
}
