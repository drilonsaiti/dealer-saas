<?php

namespace App\Domain\VehicleData\Models;

use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $stock_cycle_id
 * @property string $provider
 * @property string|null $external_id
 * @property Carbon $valued_on
 * @property int $mileage
 * @property int|null $retail_rp
 * @property int|null $trade_in_rp
 * @property string|null $reference
 * @property Carbon $created_at
 * @property-read StockCycle $stockCycle
 */
class VehicleValuation extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    protected $fillable = ['tenant_id', 'stock_cycle_id', 'provider', 'external_id', 'valued_on', 'mileage', 'retail_rp', 'trade_in_rp', 'reference'];

    protected function casts(): array
    {
        return ['valued_on' => 'date:Y-m-d', 'mileage' => 'integer', 'retail_rp' => 'integer', 'trade_in_rp' => 'integer'];
    }

    /**
     * @return BelongsTo<StockCycle, $this>
     */
    public function stockCycle(): BelongsTo
    {
        return $this->belongsTo(StockCycle::class);
    }
}
