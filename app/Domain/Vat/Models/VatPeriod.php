<?php

namespace App\Domain\Vat\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vat\Enums\VatPeriodStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One VAT return period (Aziri: half-year). While open its figures are a live preview; on
 * closing they are frozen in "figures" and never recalculated.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $vat_profile_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property VatPeriodStatus $status
 * @property array<string, mixed>|null $figures
 * @property string|null $closed_by
 * @property Carbon|null $closed_at
 * @property Carbon|null $exported_at
 * @property Carbon|null $submitted_on
 * @property string|null $submission_reference
 * @property Carbon|null $paid_on
 * @property string|null $corrects_period_id
 * @property string|null $report_document_id
 * @property string|null $detail_document_id
 * @property string|null $xml_document_id
 * @property string|null $submission_document_id
 * @property-read VatProfile $profile
 * @property-read VatPeriod|null $corrects
 */
class VatPeriod extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'vat_profile_id', 'starts_on', 'ends_on', 'corrects_period_id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'status' => VatPeriodStatus::class,
            'figures' => 'array',
            'closed_at' => 'datetime',
            'exported_at' => 'datetime',
            'submitted_on' => 'date:Y-m-d',
            'paid_on' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<VatProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(VatProfile::class, 'vat_profile_id');
    }

    /**
     * @return HasMany<TaxEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(TaxEvent::class, 'period_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function reportDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'report_document_id');
    }

    /**
     * @return BelongsTo<VatPeriod, $this>
     */
    public function corrects(): BelongsTo
    {
        return $this->belongsTo(VatPeriod::class, 'corrects_period_id');
    }

    /**
     * @return HasMany<VatPeriod, $this>
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(VatPeriod::class, 'corrects_period_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function detailDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'detail_document_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function submissionDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'submission_document_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function xmlDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'xml_document_id');
    }

    public function label(): string
    {
        $label = $this->starts_on->format('d.m.Y').' – '.$this->ends_on->format('d.m.Y');

        return $this->corrects_period_id === null ? $label : $label.' ('.__('correction').')';
    }

    public function isCorrection(): bool
    {
        return $this->corrects_period_id !== null;
    }
}
