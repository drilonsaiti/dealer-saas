<?php

namespace App\Domain\Checklists\Models;

use App\Domain\Checklists\Enums\ChecklistKind;
use App\Domain\Financing\Models\Financing;
use App\Domain\Sales\Models\Sale;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A checklist of one sale (handover) or one financing (partner documents), copied from the
 * template when it starts.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $template_id
 * @property ChecklistKind $kind
 * @property string $sale_id
 * @property string|null $financing_id
 * @property-read Collection<int, ChecklistItem> $items
 * @property-read Sale $sale
 * @property-read Financing|null $financing
 * @property-read ChecklistTemplate $template
 */
class Checklist extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'template_id', 'kind', 'sale_id', 'financing_id'];

    protected function casts(): array
    {
        return ['kind' => ChecklistKind::class];
    }

    /**
     * @return HasMany<ChecklistItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('sort');
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<Financing, $this>
     */
    public function financing(): BelongsTo
    {
        return $this->belongsTo(Financing::class);
    }

    /**
     * @return BelongsTo<ChecklistTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class);
    }

    /**
     * Required, applicable items not done yet.
     *
     * @return Collection<int, ChecklistItem>
     */
    public function openRequired(): Collection
    {
        return $this->items->filter(fn (ChecklistItem $item): bool => $item->required && $item->applicable && $item->done_at === null)->values();
    }
}
