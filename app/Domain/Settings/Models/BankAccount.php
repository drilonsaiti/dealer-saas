<?php

namespace App\Domain\Settings\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Settings\Support\Iban;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A dealer's bank account, printed on invoices and used for the Swiss QR bill.
 * The QR-IBAN is issued by the bank; we only store and validate it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $label
 * @property string|null $bank_name
 * @property string|null $account_holder
 * @property string $iban
 * @property string|null $qr_iban
 * @property string|null $bic
 * @property string $currency
 * @property bool $is_default
 */
#[UseFactory(BankAccountFactory::class)]
class BankAccount extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<BankAccountFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'label',
        'bank_name',
        'account_holder',
        'iban',
        'qr_iban',
        'bic',
        'currency',
        'is_default',
    ];

    protected $attributes = [
        'currency' => 'CHF',
        'is_default' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (BankAccount $account): void {
            if (! $account->is_default) {
                return;
            }

            static::query()
                ->where('tenant_id', $account->tenant_id)
                ->whereKeyNot($account->getKey())
                ->where('is_default', true)
                ->get()
                ->each(fn (BankAccount $other) => $other->update(['is_default' => false]));
        });
    }

    /**
     * @return Attribute<string, string>
     */
    protected function iban(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => Iban::normalize($value));
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function qrIban(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => blank($value) ? null : Iban::normalize($value));
    }

    public function hasQrIban(): bool
    {
        return $this->qr_iban !== null;
    }
}
