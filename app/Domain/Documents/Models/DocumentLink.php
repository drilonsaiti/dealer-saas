<?php

namespace App\Domain\Documents\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property string $linkable_type
 * @property string $linkable_id
 */
class DocumentLink extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'document_id', 'linkable_type', 'linkable_id'];

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
