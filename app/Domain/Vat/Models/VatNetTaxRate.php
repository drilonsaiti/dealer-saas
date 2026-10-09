<?php

namespace App\Domain\Vat\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * An approved net tax rate (Saldosteuersatz) with its activity, e.g. 0.6 % for car trade.
 * At most two per dealer (ESTV); the rate comes from the ESTV approval, never from code.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $vat_profile_id
 * @property string|null $activity_code
 * @property string $activity
 * @property string $rate
 * @property int $sort
 * @property-read VatProfile $profile
 */
class VatNetTaxRate extends Model
{
    use BelongsToTenant;
    use HasTranslations;
    use HasUuids;

    /** @var list<string> */
    public array $translatable = ['activity'];

    protected $fillable = ['tenant_id', 'vat_profile_id', 'activity_code', 'activity', 'rate', 'sort'];

    /**
     * @return BelongsTo<VatProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(VatProfile::class, 'vat_profile_id');
    }
}
