<?php

namespace App\Domain\Warranty\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\Document;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Sales\Models\Sale;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Warranty\Enums\WarrantyStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One warranty sold with a car. Draft on the sale (cost and price already in the margin),
 * active from handover with start date and km; claims hang off it.
 *
 * @property string $id
 * @property Carbon|null $submitted_at
 * @property string|null $submission_email_id
 * @property string $tenant_id
 * @property string $stock_cycle_id
 * @property string|null $sale_id
 * @property string $product_id
 * @property string|null $sale_item_id
 * @property string|null $cost_id
 * @property string|null $policy_number
 * @property int $duration_months
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property int|null $km_at_start
 * @property int|null $km_limit
 * @property int|null $coverage_limit_rp
 * @property int $deductible_rp
 * @property int $cost_rp
 * @property int $price_rp
 * @property WarrantyStatus $status
 * @property string|null $certificate_document_id
 * @property-read WarrantyProduct $product
 * @property-read StockCycle $stockCycle
 * @property-read Sale|null $sale
 * @property-read Cost|null $cost
 * @property-read Document|null $certificate
 */
class Warranty extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'stock_cycle_id', 'sale_id', 'product_id', 'policy_number', 'duration_months', 'km_limit', 'coverage_limit_rp', 'deductible_rp', 'cost_rp', 'price_rp'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'km_at_start' => 'integer',
            'km_limit' => 'integer',
            'coverage_limit_rp' => 'integer',
            'deductible_rp' => 'integer',
            'cost_rp' => 'integer',
            'price_rp' => 'integer',
            'duration_months' => 'integer',
            'status' => WarrantyStatus::class,
        ];
    }

    /**
     * Active warranties ending within the next $days days.
     *
     * @param  Builder<Warranty>  $query
     */
    public function scopeExpiringWithin(Builder $query, int $days): void
    {
        $query->where('status', WarrantyStatus::Active->value)
            ->whereDate('ends_on', '>=', Carbon::today())
            ->whereDate('ends_on', '<=', Carbon::today()->addDays($days));
    }

    /**
     * The km reading up to which the warranty covers (null = no km limit).
     */
    public function kmUntil(): ?int
    {
        return $this->km_at_start === null || $this->km_limit === null ? null : $this->km_at_start + $this->km_limit;
    }

    /**
     * @return BelongsTo<WarrantyProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(WarrantyProduct::class, 'product_id');
    }

    /**
     * @return BelongsTo<StockCycle, $this>
     */
    public function stockCycle(): BelongsTo
    {
        return $this->belongsTo(StockCycle::class);
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<Cost, $this>
     */
    public function cost(): BelongsTo
    {
        return $this->belongsTo(Cost::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'certificate_document_id');
    }

    /**
     * @return HasMany<WarrantyClaim, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class);
    }
}
