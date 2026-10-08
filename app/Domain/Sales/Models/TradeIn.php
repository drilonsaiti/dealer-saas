<?php

namespace App\Domain\Sales\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Parties\Models\Party;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eintausch: the customer's car taken in part payment. Until confirmed it only describes the
 * car (vehicle_data); confirming creates (or finds) the vehicle and opens its own file with
 * purchase type "trade-in". credited_rp is computed by the database.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sale_id
 * @property array<string, mixed> $vehicle_data
 * @property int|null $mileage
 * @property string|null $purchase_cycle_id
 * @property int $value_rp
 * @property int $payoff_rp
 * @property string|null $payoff_party_id
 * @property int $customer_payout_rp
 * @property int $customer_topup_rp
 * @property int $credited_rp
 * @property string|null $settlement_basis
 * @property string|null $condition_notes
 * @property Carbon|null $confirmed_at
 * @property-read Sale $sale
 * @property-read StockCycle|null $purchaseCycle
 */
class TradeIn extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'sale_id',
        'vehicle_data',
        'mileage',
        'value_rp',
        'payoff_rp',
        'payoff_party_id',
        'customer_payout_rp',
        'customer_topup_rp',
        'settlement_basis',
        'condition_notes',
    ];

    protected $attributes = [
        'payoff_rp' => 0,
        'customer_payout_rp' => 0,
        'customer_topup_rp' => 0,
    ];

    protected function casts(): array
    {
        return [
            'vehicle_data' => 'array',
            'mileage' => 'integer',
            'value_rp' => 'integer',
            'payoff_rp' => 'integer',
            'customer_payout_rp' => 'integer',
            'customer_topup_rp' => 'integer',
            'credited_rp' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<StockCycle, $this>
     */
    public function purchaseCycle(): BelongsTo
    {
        return $this->belongsTo(StockCycle::class, 'purchase_cycle_id');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function payoffParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'payoff_party_id');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function vehicleName(): string
    {
        $data = $this->vehicle_data;
        $name = trim(implode(' ', array_filter([$data['make'] ?? null, $data['model'] ?? null, $data['variant'] ?? null])));

        return $name !== '' ? $name : __('Trade-in vehicle');
    }
}
