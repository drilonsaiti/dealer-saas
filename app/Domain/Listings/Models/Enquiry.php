<?php

namespace App\Domain\Listings\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Listings\Enums\EnquiryStatus;
use App\Domain\Parties\Models\Party;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Models\StockCycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer's question from the website (enquiry form) or the API, linked to the vehicle
 * and to the contact (found by e-mail or phone, or created).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $listing_id
 * @property string|null $stock_cycle_id
 * @property string|null $party_id
 * @property string $source
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string $message
 * @property string|null $locale
 * @property EnquiryStatus $status
 * @property string|null $handled_by
 * @property Carbon|null $handled_at
 * @property string|null $ip
 * @property Carbon $created_at
 * @property-read Listing|null $listing
 * @property-read StockCycle|null $stockCycle
 * @property-read Party|null $party
 * @property-read User|null $handler
 */
class Enquiry extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'listing_id', 'stock_cycle_id', 'party_id', 'source', 'name', 'email', 'phone', 'message', 'locale', 'ip'];

    protected $attributes = ['status' => 'new', 'source' => 'website'];

    protected function casts(): array
    {
        return ['status' => EnquiryStatus::class, 'handled_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<StockCycle, $this>
     */
    public function stockCycle(): BelongsTo
    {
        return $this->belongsTo(StockCycle::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
