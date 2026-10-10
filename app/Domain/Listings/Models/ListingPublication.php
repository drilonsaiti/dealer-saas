<?php

namespace App\Domain\Listings\Models;

use App\Domain\Listings\Enums\PublicationStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A listing on one channel (website, AutoScout24) with its external id and the last sync.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $listing_id
 * @property string $channel
 * @property PublicationStatus $status
 * @property string|null $external_id
 * @property string|null $external_url
 * @property string|null $payload_hash
 * @property Carbon|null $last_synced_at
 * @property string|null $last_error
 * @property-read Listing $listing
 */
class ListingPublication extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const WEBSITE = 'website';

    protected $fillable = ['tenant_id', 'listing_id', 'channel'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['status' => PublicationStatus::class, 'last_synced_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
