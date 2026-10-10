<?php

namespace App\Domain\Preparation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Parties\Models\Party;
use App\Domain\Preparation\Enums\RepairOrderStatus;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Work given to a workshop: estimate → approved (counts in the margin as an estimate) →
 * done (the actual cost becomes a confirmed cost of the file). Open orders that block the
 * release keep the car from becoming ready for sale.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $stock_cycle_id
 * @property string|null $workshop_party_id
 * @property string $description
 * @property RepairOrderStatus $status
 * @property int|null $estimate_rp
 * @property int|null $approved_rp
 * @property Carbon|null $approved_at
 * @property string|null $approved_by
 * @property Carbon|null $target_on
 * @property Carbon|null $done_on
 * @property string|null $cost_id
 * @property bool $blocks_release
 * @property-read StockCycle $stockCycle
 * @property-read Party|null $workshop
 * @property-read Cost|null $cost
 * @property-read Collection<int, Damage> $damages
 */
class RepairOrder extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    protected $fillable = ['tenant_id', 'stock_cycle_id', 'workshop_party_id', 'description', 'estimate_rp', 'target_on', 'blocks_release'];

    protected $attributes = ['status' => 'estimate', 'blocks_release' => true];

    protected function casts(): array
    {
        return [
            'status' => RepairOrderStatus::class,
            'estimate_rp' => 'integer',
            'approved_rp' => 'integer',
            'approved_at' => 'datetime',
            'target_on' => 'date:Y-m-d',
            'done_on' => 'date:Y-m-d',
            'blocks_release' => 'boolean',
        ];
    }

    /**
     * @param  Builder<RepairOrder>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [RepairOrderStatus::Estimate->value, RepairOrderStatus::Approved->value]);
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

    /**
     * @return HasMany<Damage, $this>
     */
    public function damages(): HasMany
    {
        return $this->hasMany(Damage::class);
    }
}
