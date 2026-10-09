<?php

namespace App\Domain\Vat\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vat\Enums\VatBasis;
use App\Domain\Vat\Enums\VatMethod;
use App\Domain\Vat\Enums\VatPeriodLength;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The dealer's VAT situation from a date on: liable or not, method, basis, period and the
 * approved net tax rates. Changes are new profiles with a later valid_from; closed periods
 * keep their figures.
 *
 * @property string $id
 * @property string $tenant_id
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property bool $liable
 * @property string|null $vat_number
 * @property VatMethod $method
 * @property VatBasis $basis
 * @property VatPeriodLength $period
 * @property Carbon|null $approved_on
 * @property string|null $notes
 * @property-read Collection<int, VatNetTaxRate> $netTaxRates
 */
class VatProfile extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    protected $fillable = ['tenant_id', 'valid_from', 'valid_to', 'liable', 'vat_number', 'method', 'basis', 'period', 'approved_on', 'notes'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date:Y-m-d',
            'valid_to' => 'date:Y-m-d',
            'approved_on' => 'date:Y-m-d',
            'liable' => 'boolean',
            'method' => VatMethod::class,
            'basis' => VatBasis::class,
            'period' => VatPeriodLength::class,
        ];
    }

    /**
     * @return HasMany<VatNetTaxRate, $this>
     */
    public function netTaxRates(): HasMany
    {
        return $this->hasMany(VatNetTaxRate::class)->orderBy('sort');
    }

    public static function validOn(DateTimeInterface $date): ?self
    {
        $day = $date->format('Y-m-d');

        return self::query()
            ->where('valid_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $day))
            ->with('netTaxRates')
            ->orderByDesc('valid_from')
            ->first();
    }
}
