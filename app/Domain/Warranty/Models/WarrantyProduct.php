<?php

namespace App\Domain\Warranty\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Parties\Models\Party;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * A warranty the dealer sells: own warranty (no provider) or a provider's product
 * (e.g. SuisseFox FoxG3, 12 months / 20'000 km). Cost to the dealer and price to the customer.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $provider_party_id
 * @property string $name
 * @property int $duration_months
 * @property int|null $km_limit
 * @property int|null $coverage_limit_rp
 * @property string|null $coverage
 * @property int $deductible_rp
 * @property int $cost_rp
 * @property int $price_rp
 * @property int $commission_rp
 * @property bool $is_active
 * @property-read Party|null $provider
 */
class WarrantyProduct extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasTranslations;
    use HasUuids;

    /** @var list<string> */
    public array $translatable = ['name', 'coverage'];

    protected $fillable = ['tenant_id', 'provider_party_id', 'name', 'duration_months', 'km_limit', 'coverage_limit_rp', 'coverage', 'deductible_rp', 'cost_rp', 'price_rp', 'commission_rp', 'is_active'];

    protected function casts(): array
    {
        return [
            'duration_months' => 'integer',
            'km_limit' => 'integer',
            'coverage_limit_rp' => 'integer',
            'deductible_rp' => 'integer',
            'cost_rp' => 'integer',
            'price_rp' => 'integer',
            'commission_rp' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'provider_party_id');
    }

    public function isOwnWarranty(): bool
    {
        return $this->provider_party_id === null;
    }
}
