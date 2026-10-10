<?php

namespace App\Domain\Preparation\Models;

use App\Domain\Documents\Models\Document;
use App\Domain\Preparation\Enums\DamageArea;
use App\Domain\Preparation\Enums\DamageKind;
use App\Domain\Preparation\Enums\DamageSeverity;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * One damage found in a condition report; photos are documents linked to it (and to the file).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $condition_report_id
 * @property string $stock_cycle_id
 * @property DamageArea $area
 * @property DamageKind $kind
 * @property DamageSeverity $severity
 * @property string|null $notes
 * @property string|null $repair_order_id
 * @property-read ConditionReport $report
 * @property-read RepairOrder|null $repairOrder
 */
class Damage extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'condition_report_id', 'stock_cycle_id', 'area', 'kind', 'severity', 'notes', 'repair_order_id'];

    protected function casts(): array
    {
        return ['area' => DamageArea::class, 'kind' => DamageKind::class, 'severity' => DamageSeverity::class];
    }

    public function label(): string
    {
        return $this->area->getLabel().': '.$this->kind->getLabel().($this->notes ? ' ('.$this->notes.')' : '');
    }

    /**
     * @return BelongsTo<ConditionReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(ConditionReport::class, 'condition_report_id');
    }

    /**
     * @return BelongsTo<StockCycle, $this>
     */
    public function stockCycle(): BelongsTo
    {
        return $this->belongsTo(StockCycle::class);
    }

    /**
     * @return BelongsTo<RepairOrder, $this>
     */
    public function repairOrder(): BelongsTo
    {
        return $this->belongsTo(RepairOrder::class);
    }

    /**
     * @return MorphToMany<Document, $this>
     */
    public function photos(): MorphToMany
    {
        return $this->morphToMany(Document::class, 'linkable', 'document_links');
    }
}
