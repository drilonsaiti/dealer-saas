<?php

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Documents\Enums\FolderGroup;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * Document category (Kaufvertrag, Fahrzeugausweis, Werkstattrechnung...) in its folder.
 * Sensitive categories (ID copies, registration with the previous owner's data, budget
 * calculations) are visible only with the documents.view_sensitive permission.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $name
 * @property FolderGroup $folder_group
 * @property bool $sensitive
 * @property int $retention_years
 * @property bool $is_active
 * @property int $sort
 */
class DocumentCategory extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasTranslations;
    use HasUuids;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = ['tenant_id', 'key', 'name', 'folder_group', 'sensitive', 'retention_years', 'is_active', 'sort'];

    protected $attributes = [
        'sensitive' => false,
        'retention_years' => 10,
        'is_active' => true,
        'sort' => 100,
    ];

    protected function casts(): array
    {
        return [
            'folder_group' => FolderGroup::class,
            'sensitive' => 'boolean',
            'retention_years' => 'integer',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @param  Builder<DocumentCategory>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('folder_group')->orderBy('sort');
    }

    /**
     * Options grouped by folder, for selects.
     *
     * @return array<string, array<string, string>>
     */
    public static function groupedOptions(bool $includeSensitive = true): array
    {
        return self::query()
            ->active()
            ->when(! $includeSensitive, fn (Builder $query) => $query->where('sensitive', false))
            ->get()
            ->groupBy(fn (self $category): string => $category->folder_group->getLabel())
            ->map(fn ($group) => $group->mapWithKeys(fn (self $category): array => [$category->id => $category->name])->all())
            ->all();
    }
}
