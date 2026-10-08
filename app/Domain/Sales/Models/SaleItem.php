<?php

namespace App\Domain\Sales\Models;

use App\Domain\Sales\Enums\SaleItemKind;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something sold with the car: accessory, service package, tyres, warranty...
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sale_id
 * @property SaleItemKind $kind
 * @property string $description
 * @property string $qty
 * @property int $unit_price_rp
 * @property int $sort
 */
class SaleItem extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'sale_id',
        'kind',
        'description',
        'qty',
        'unit_price_rp',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SaleItemKind::class,
            'qty' => 'decimal:2',
            'unit_price_rp' => 'integer',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function totalRp(): int
    {
        // qty has two decimals; multiply in hundredths to stay in integers.
        return intdiv((int) round(((float) $this->qty) * 100) * $this->unit_price_rp, 100);
    }
}
