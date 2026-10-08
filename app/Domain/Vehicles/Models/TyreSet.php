<?php

namespace App\Domain\Vehicles\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Enums\TyreSeason;
use Database\Factories\TyreSetFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $vehicle_id
 * @property TyreSeason $season
 * @property string|null $dimension
 * @property string|null $tread_mm
 * @property bool $on_rims
 * @property string|null $rim_type
 * @property string|null $location
 */
#[UseFactory(TyreSetFactory::class)]
class TyreSet extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<TyreSetFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'vehicle_id',
        'season',
        'dimension',
        'tread_mm',
        'on_rims',
        'rim_type',
        'location',
    ];

    protected $attributes = [
        'on_rims' => false,
    ];

    protected function casts(): array
    {
        return [
            'season' => TyreSeason::class,
            'tread_mm' => 'decimal:1',
            'on_rims' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
