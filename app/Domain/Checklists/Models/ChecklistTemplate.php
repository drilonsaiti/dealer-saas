<?php

namespace App\Domain\Checklists\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Checklists\Enums\ChecklistKind;
use App\Domain\Parties\Models\Party;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * A dealer's checklist template (handover, or the documents a financing partner needs).
 * Editing makes a new version; checklists already started keep their items.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ChecklistKind $kind
 * @property string|null $partner_party_id
 * @property string $name
 * @property int $version
 * @property bool $is_active
 * @property-read Collection<int, ChecklistTemplateItem> $items
 * @property-read Party|null $partner
 */
class ChecklistTemplate extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasTranslations;
    use HasUuids;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = ['tenant_id', 'kind', 'partner_party_id', 'name', 'version', 'is_active'];

    protected function casts(): array
    {
        return [
            'kind' => ChecklistKind::class,
            'version' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ChecklistTemplateItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ChecklistTemplateItem::class, 'template_id')->orderBy('sort');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'partner_party_id');
    }
}
