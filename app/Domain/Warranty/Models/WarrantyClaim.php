<?php

namespace App\Domain\Warranty\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Parties\Models\Party;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Warranty\Enums\ClaimStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A warranty case. When settled, the dealer's share becomes a cost on the original vehicle
 * file, so the real margin of that sale stays correct.
 *
 * @property string $id
 * @property Carbon|null $reported_at
 * @property string|null $report_email_id
 * @property string $tenant_id
 * @property string $warranty_id
 * @property Carbon $occurred_on
 * @property int $mileage
 * @property string $description
 * @property string|null $diagnosis
 * @property string|null $workshop_party_id
 * @property int $amount_rp
 * @property int $deductible_rp
 * @property int $provider_share_rp
 * @property int $dealer_share_rp
 * @property ClaimStatus $status
 * @property string|null $cost_id
 * @property Carbon|null $closed_on
 * @property-read Warranty $warranty
 * @property-read Party|null $workshop
 * @property-read Cost|null $cost
 */
class WarrantyClaim extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    protected $fillable = ['tenant_id', 'warranty_id', 'occurred_on', 'mileage', 'description', 'diagnosis', 'workshop_party_id', 'amount_rp', 'deductible_rp', 'provider_share_rp', 'dealer_share_rp'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'occurred_on' => 'date:Y-m-d',
            'closed_on' => 'date:Y-m-d',
            'mileage' => 'integer',
            'amount_rp' => 'integer',
            'deductible_rp' => 'integer',
            'provider_share_rp' => 'integer',
            'dealer_share_rp' => 'integer',
            'status' => ClaimStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Warranty, $this>
     */
    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'workshop_party_id');
    }

    /**
     * @return BelongsTo<Cost, $this>
     */
    public function cost(): BelongsTo
    {
        return $this->belongsTo(Cost::class);
    }
}
