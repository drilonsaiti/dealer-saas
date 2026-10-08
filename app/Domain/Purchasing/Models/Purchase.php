<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Parties\Models\Party;
use App\Domain\Purchasing\Enums\PaymentStatus;
use App\Domain\Purchasing\Enums\PurchaseType;
use App\Domain\Purchasing\Enums\SellerKind;
use App\Domain\Purchasing\Enums\VatSituation;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Models\StockCycle;
use Database\Factories\PurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ankauf: how the dealer got the car on this cycle. One per stock cycle.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $stock_cycle_id
 * @property string|null $seller_party_id
 * @property SellerKind $seller_kind
 * @property PurchaseType $purchase_type
 * @property Carbon $contract_on
 * @property Carbon|null $delivered_on
 * @property int $price_rp
 * @property VatSituation $vat_situation
 * @property int|null $vat_shown_rp
 * @property int|null $mileage
 * @property int|null $payoff_rp
 * @property string|null $payoff_party_id
 * @property string|null $known_defects
 * @property string|null $agreed_deliverables
 * @property PaymentStatus $payment_status
 * @property-read StockCycle $stockCycle
 * @property-read Party|null $seller
 */
#[UseFactory(PurchaseFactory::class)]
class Purchase extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<PurchaseFactory> */
    use HasFactory;

    use HasUuids;
    use TracksAuthors;

    protected $fillable = [
        'tenant_id',
        'stock_cycle_id',
        'seller_party_id',
        'seller_kind',
        'purchase_type',
        'contract_on',
        'delivered_on',
        'price_rp',
        'vat_situation',
        'vat_shown_rp',
        'mileage',
        'payoff_rp',
        'payoff_party_id',
        'known_defects',
        'agreed_deliverables',
        'payment_status',
    ];

    protected $attributes = [
        'purchase_type' => 'direct',
        'vat_situation' => 'unknown',
        'payment_status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'seller_kind' => SellerKind::class,
            'purchase_type' => PurchaseType::class,
            'vat_situation' => VatSituation::class,
            'payment_status' => PaymentStatus::class,
            'contract_on' => 'date:Y-m-d',
            'delivered_on' => 'date:Y-m-d',
            'price_rp' => 'integer',
            'vat_shown_rp' => 'integer',
            'mileage' => 'integer',
            'payoff_rp' => 'integer',
        ];
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
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'seller_party_id');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function payoffParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'payoff_party_id');
    }
}
