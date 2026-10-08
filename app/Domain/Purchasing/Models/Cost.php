<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Parties\Models\Party;
use App\Domain\Purchasing\Enums\CostStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Models\StockCycle;
use Database\Factories\CostFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One cost line: on a vehicle file (transport, repair, tyres...) or, without a file,
 * a general operating cost. Draft until confirmed; margins stay "provisional" while
 * any cost of the file is a draft or an estimate.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $stock_cycle_id
 * @property string $category_id
 * @property Carbon $incurred_on
 * @property string|null $description
 * @property string|null $supplier_party_id
 * @property string|null $document_id
 * @property int $gross_rp
 * @property int|null $vat_rp
 * @property bool $is_estimate
 * @property CostStatus $status
 * @property string|null $split_group_id
 * @property string|null $commitment_id
 * @property string|null $legacy_ref
 * @property-read CostCategory $category
 * @property-read StockCycle|null $stockCycle
 * @property-read Party|null $supplier
 */
#[UseFactory(CostFactory::class)]
class Cost extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<CostFactory> */
    use HasFactory;

    use HasUuids;
    use TracksAuthors;

    protected $fillable = [
        'tenant_id',
        'stock_cycle_id',
        'category_id',
        'incurred_on',
        'description',
        'supplier_party_id',
        'gross_rp',
        'vat_rp',
        'is_estimate',
        'commitment_id',
        'legacy_ref',
    ];

    protected $attributes = [
        'is_estimate' => false,
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'incurred_on' => 'date:Y-m-d',
            'gross_rp' => 'integer',
            'vat_rp' => 'integer',
            'is_estimate' => 'boolean',
            'status' => CostStatus::class,
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
     * @return BelongsTo<CostCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CostCategory::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_party_id');
    }

    /**
     * @return BelongsTo<Commitment, $this>
     */
    public function commitment(): BelongsTo
    {
        return $this->belongsTo(Commitment::class);
    }

    public function isConfirmed(): bool
    {
        return $this->status === CostStatus::Confirmed;
    }
}
