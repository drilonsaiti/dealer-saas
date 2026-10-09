<?php

namespace App\Domain\Vat\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vat\Actions\InstallDefaultVatCodes;
use App\Domain\Vat\Enums\VatCodeKind;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * How a line is treated for VAT (taxable at the normal rate, no tax shown, export...).
 * The rate itself comes from the dated legal rates, never from the code.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $label
 * @property VatCodeKind $kind
 * @property string|null $vat_rate_code
 * @property string|null $estv_field
 * @property string|null $account
 * @property bool $is_active
 */
class VatCode extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasTranslations;
    use HasUuids;

    /** @var list<string> */
    public array $translatable = ['label'];

    protected $fillable = ['tenant_id', 'key', 'label', 'kind', 'vat_rate_code', 'estv_field', 'account', 'is_active'];

    protected function casts(): array
    {
        return [
            'kind' => VatCodeKind::class,
            'is_active' => 'boolean',
        ];
    }

    public function percentOn(DateTimeInterface $on): float
    {
        return $this->kind->hasRate() && $this->vat_rate_code !== null ? VatRate::percentFor($this->vat_rate_code, $on) : 0.0;
    }

    public static function byKey(string $key): self
    {
        $code = self::query()->where('key', $key)->first();

        if ($code === null) {
            app(InstallDefaultVatCodes::class)();
            $code = self::query()->where('key', $key)->firstOrFail();
        }

        return $code;
    }
}
