<?php

namespace App\Domain\Checklists\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $template_id
 * @property string $key
 * @property string $label
 * @property bool $required
 * @property string|null $auto_rule
 * @property int $sort
 */
class ChecklistTemplateItem extends Model
{
    use BelongsToTenant;
    use HasTranslations;
    use HasUuids;

    /** @var list<string> */
    public array $translatable = ['label'];

    protected $fillable = ['tenant_id', 'template_id', 'key', 'label', 'required', 'auto_rule', 'sort'];

    protected function casts(): array
    {
        return ['required' => 'boolean', 'sort' => 'integer'];
    }
}
