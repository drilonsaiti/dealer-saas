<?php

namespace App\Domain\Sales\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Checklists\Models\Checklist;
use App\Domain\Financing\Enums\FinancingStatus;
use App\Domain\Financing\Models\Financing;
use App\Domain\Parties\Models\Party;
use App\Domain\Sales\Enums\PaymentType;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Warranty\Models\Warranty;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Verkauf of one vehicle file. In a leasing deal the invoice goes to the financing partner
 * (invoice recipient) while the customer is buyer and holder. Status changes only through
 * the sales actions (reserve, contract, cancel, hand over).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $stock_cycle_id
 * @property SaleStatus $status
 * @property string $buyer_party_id
 * @property string|null $invoice_recipient_party_id
 * @property string|null $holder_party_id
 * @property Carbon|null $sale_on
 * @property Carbon|null $planned_handover_on
 * @property Carbon|null $reserved_until
 * @property int $price_rp
 * @property int $discount_rp
 * @property int $deposit_rp
 * @property PaymentType $payment_type
 * @property int|null $mileage_at_handover
 * @property Carbon|null $delivered_on
 * @property string $locale
 * @property string|null $remarks
 * @property string|null $cancel_reason
 * @property Carbon|null $cancelled_at
 * @property-read StockCycle $stockCycle
 * @property-read Party $buyer
 * @property-read TradeIn|null $tradeIn
 * @property-read Financing|null $financing
 */
#[UseFactory(SaleFactory::class)]
class Sale extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    use HasUuids;
    use TracksAuthors;

    protected $fillable = [
        'tenant_id',
        'stock_cycle_id',
        'buyer_party_id',
        'invoice_recipient_party_id',
        'holder_party_id',
        'sale_on',
        'planned_handover_on',
        'reserved_until',
        'price_rp',
        'discount_rp',
        'deposit_rp',
        'payment_type',
        'locale',
        'remarks',
        'legacy_ref',
    ];

    protected $attributes = [
        'discount_rp' => 0,
        'deposit_rp' => 0,
        'payment_type' => 'bank',
        'locale' => 'de',
    ];

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'payment_type' => PaymentType::class,
            'sale_on' => 'date:Y-m-d',
            'planned_handover_on' => 'date:Y-m-d',
            'reserved_until' => 'date:Y-m-d',
            'delivered_on' => 'date:Y-m-d',
            'cancelled_at' => 'datetime',
            'price_rp' => 'integer',
            'discount_rp' => 'integer',
            'deposit_rp' => 'integer',
            'mileage_at_handover' => 'integer',
        ];
    }

    /**
     * @param  Builder<Sale>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', '<>', SaleStatus::Cancelled->value);
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
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'buyer_party_id');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function invoiceRecipient(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'invoice_recipient_party_id');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'holder_party_id');
    }

    /**
     * @return HasMany<SaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('sort');
    }

    /**
     * @return HasOne<TradeIn, $this>
     */
    public function tradeIn(): HasOne
    {
        return $this->hasOne(TradeIn::class);
    }

    /**
     * Vehicle price after discount plus the extra items (accessories, services, warranty...).
     */
    public function totalRp(): int
    {
        $items = $this->items->sum(fn (SaleItem $item): int => $item->totalRp());

        return $this->price_rp - $this->discount_rp + (int) $items;
    }

    /**
     * What the customer still has to pay after deposit and trade-in credit.
     */
    public function balanceRp(): int
    {
        $credited = $this->tradeIn === null ? 0 : $this->tradeIn->credited_rp;

        return $this->totalRp() - $this->deposit_rp - $credited;
    }

    /**
     * The leasing or credit of this sale (not rejected or cancelled).
     *
     * @return HasOne<Financing, $this>
     */
    public function financing(): HasOne
    {
        return $this->hasOne(Financing::class)->whereNotIn('status', [FinancingStatus::Rejected->value, FinancingStatus::Cancelled->value]);
    }

    /**
     * @return HasMany<Warranty, $this>
     */
    public function warranties(): HasMany
    {
        return $this->hasMany(Warranty::class);
    }

    /**
     * @return HasMany<Checklist, $this>
     */
    public function checklists(): HasMany
    {
        return $this->hasMany(Checklist::class);
    }
}
