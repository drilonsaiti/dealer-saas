<?php

namespace App\Domain\Checklists\Models;

use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * One item. With an automatic rule it ticks itself (auto = true) from the records; without
 * one a user ticks it, optionally with an evidence document.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $checklist_id
 * @property string $key
 * @property string $label
 * @property bool $required
 * @property string|null $auto_rule
 * @property int $sort
 * @property bool $applicable
 * @property Carbon|null $done_at
 * @property string|null $done_by
 * @property bool $auto
 * @property string|null $evidence_document_id
 * @property-read Checklist $checklist
 * @property-read Document|null $evidence
 */
class ChecklistItem extends Model
{
    use BelongsToTenant;
    use HasTranslations;
    use HasUuids;

    /** @var list<string> */
    public array $translatable = ['label'];

    protected $fillable = ['tenant_id', 'checklist_id', 'key', 'label', 'required', 'auto_rule', 'sort'];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'applicable' => 'boolean',
            'auto' => 'boolean',
            'done_at' => 'datetime',
            'sort' => 'integer',
        ];
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    /**
     * @return BelongsTo<Checklist, $this>
     */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'evidence_document_id');
    }
}
