<?php

namespace App\Domain\Vat\Models;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vat\Enums\TaxEventState;
use App\Domain\Vat\Enums\VatCodeKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One tax-relevant fact (an invoice line, its payment, a credit note line), produced by a
 * versioned rule with its explanation. Only auto and confirmed events count in a period.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $source_type
 * @property string $source_id
 * @property string|null $invoice_id
 * @property Carbon $event_on
 * @property string|null $vat_code_id
 * @property VatCodeKind $kind
 * @property string $field
 * @property int $base_rp
 * @property string $legal_rate
 * @property int $tax_rp
 * @property string|null $net_tax_rate_id
 * @property string|null $net_rate
 * @property string|null $period_id
 * @property bool $late
 * @property TaxEventState $state
 * @property string $rule_key
 * @property string $rule_version
 * @property array<string, mixed> $explanation
 * @property string|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property-read Invoice|null $invoice
 * @property-read VatPeriod|null $period
 * @property-read VatNetTaxRate|null $netTaxRate
 */
class TaxEvent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'source_type', 'source_id', 'invoice_id', 'event_on', 'vat_code_id', 'kind', 'field', 'base_rp', 'legal_rate', 'tax_rp', 'net_tax_rate_id', 'net_rate', 'period_id', 'late', 'state', 'rule_key', 'rule_version', 'explanation'];

    protected function casts(): array
    {
        return [
            'event_on' => 'date:Y-m-d',
            'kind' => VatCodeKind::class,
            'base_rp' => 'integer',
            'tax_rp' => 'integer',
            'late' => 'boolean',
            'state' => TaxEventState::class,
            'explanation' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<TaxEvent>  $query
     */
    public function scopeCounting(Builder $query): void
    {
        $query->where('state', TaxEventState::Auto->value)->orWhere(fn (Builder $q) => $q->where('state', TaxEventState::Confirm->value)->whereNotNull('confirmed_at'));
    }

    public function counts(): bool
    {
        return $this->state === TaxEventState::Auto || ($this->state === TaxEventState::Confirm && $this->confirmed_at !== null);
    }

    /**
     * The rule's explanation in the current language, one sentence per step.
     */
    public function explanationText(): string
    {
        $sentences = [];

        foreach ($this->explanation['steps'] ?? [] as $step) {
            if (is_array($step) && is_string($step['text'] ?? null)) {
                $sentences[] = (string) __($step['text'], is_array($step['params'] ?? null) ? $step['params'] : []);
            }
        }

        return implode(' ', $sentences);
    }

    public function missingText(): ?string
    {
        return isset($this->explanation['missing']) ? (string) __($this->explanation['missing']) : null;
    }

    public function isOpenCheck(): bool
    {
        return ! $this->counts();
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<VatPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(VatPeriod::class, 'period_id');
    }

    /**
     * @return BelongsTo<VatNetTaxRate, $this>
     */
    public function netTaxRate(): BelongsTo
    {
        return $this->belongsTo(VatNetTaxRate::class, 'net_tax_rate_id');
    }
}
