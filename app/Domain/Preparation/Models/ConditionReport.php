<?php

namespace App\Domain\Preparation\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Models\StockCycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The condition of a car at a moment (usually on arrival): a rating per area and its damages
 * with photos.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $stock_cycle_id
 * @property Carbon $reported_on
 * @property string|null $user_id
 * @property int|null $mileage
 * @property string|null $summary
 * @property array<string, array{rating: string, note?: string|null}>|null $items
 * @property-read StockCycle $stockCycle
 * @property-read User|null $user
 * @property-read Collection<int, Damage> $damages
 */
class ConditionReport extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;

    public const AREAS = ['exterior', 'interior', 'tyres', 'brakes', 'engine', 'electrics', 'glass', 'documents'];

    protected $fillable = ['tenant_id', 'stock_cycle_id', 'reported_on', 'user_id', 'mileage', 'summary', 'items'];

    protected function casts(): array
    {
        return ['reported_on' => 'date:Y-m-d', 'mileage' => 'integer', 'items' => 'array'];
    }

    /**
     * @return array<string, string>
     */
    public static function areaLabels(): array
    {
        return [
            'exterior' => __('Body / paint'),
            'interior' => __('Interior'),
            'tyres' => __('Tyres'),
            'brakes' => __('Brakes'),
            'engine' => __('Engine / gearbox'),
            'electrics' => __('Electrics / lights'),
            'glass' => __('Glass'),
            'documents' => __('Documents / keys'),
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Damage, $this>
     */
    public function damages(): HasMany
    {
        return $this->hasMany(Damage::class);
    }
}
