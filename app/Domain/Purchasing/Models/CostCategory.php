<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * Cost category with its name in all four languages. Each dealer starts with the defaults
 * (InstallDefaultCostCategories) and can rename, add or deactivate categories.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $name
 * @property bool $counts_toward_margin
 * @property bool $is_active
 * @property int $sort
 */
class CostCategory extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasTranslations;
    use HasUuids;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = [
        'tenant_id',
        'key',
        'name',
        'counts_toward_margin',
        'is_active',
        'sort',
    ];

    protected $attributes = [
        'counts_toward_margin' => true,
        'is_active' => true,
        'sort' => 100,
    ];

    protected function casts(): array
    {
        return [
            'counts_toward_margin' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @param  Builder<CostCategory>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort');
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::query()->active()->get()->mapWithKeys(fn (self $category): array => [$category->id => $category->name])->all();
    }
}
