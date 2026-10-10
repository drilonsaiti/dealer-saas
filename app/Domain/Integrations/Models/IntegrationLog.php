<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Listings\Models\Listing;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One call to an external service (sync log, visible to the dealer).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $integration_account_id
 * @property string|null $listing_id
 * @property string $action
 * @property string $status
 * @property string|null $message
 * @property int|null $response_code
 * @property int|null $duration_ms
 * @property Carbon $created_at
 * @property-read Listing|null $listing
 */
class IntegrationLog extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'integration_account_id', 'listing_id', 'action', 'status', 'message', 'response_code', 'duration_ms'];

    protected function casts(): array
    {
        return ['response_code' => 'integer', 'duration_ms' => 'integer', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
