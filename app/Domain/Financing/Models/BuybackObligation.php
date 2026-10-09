<?php

namespace App\Domain\Financing\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Financing\Enums\BuybackStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The dealer's promise to buy the car back from the bank at lease end (a contingent
 * liability). Exercising it opens a new vehicle file on the same vehicle (purchase type buy-back).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $financing_id
 * @property string $vehicle_id
 * @property int $amount_rp
 * @property Carbon $due_on
 * @property Carbon $remind_on
 * @property BuybackStatus $status
 * @property string|null $stock_cycle_id
 * @property Carbon|null $settled_on
 * @property string|null $notes
 * @property-read Financing $financing
 * @property-read Vehicle $vehicle
 * @property-read StockCycle|null $stockCycle
 */
class BuybackObligation extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'financing_id', 'vehicle_id', 'amount_rp', 'due_on', 'remind_on', 'notes'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'amount_rp' => 'integer',
            'due_on' => 'date:Y-m-d',
            'remind_on' => 'date:Y-m-d',
            'settled_on' => 'date:Y-m-d',
            'status' => BuybackStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Financing, $this>
     */
    public function financing(): BelongsTo
    {
        return $this->belongsTo(Financing::class);
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return BelongsTo<StockCycle, $this>
     */
    public function stockCycle(): BelongsTo
    {
        return $this->belongsTo(StockCycle::class);
    }
}
