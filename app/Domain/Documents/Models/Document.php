<?php

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A document of a vehicle file (or of a party): metadata plus its versions. A replacement is
 * always a new version; nothing is overwritten. One document can belong to several records
 * (vehicle file, party, sale) through document_links.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $category_id
 * @property string $title
 * @property Carbon|null $document_on
 * @property string|null $locale
 * @property DocumentSource $source
 * @property DocumentStatus $status
 * @property string|null $current_version_id
 * @property string|null $possible_duplicate_of_id
 * @property string|null $legacy_ref
 * @property-read DocumentCategory $category
 * @property-read DocumentVersion|null $currentVersion
 * @property-read Document|null $possibleDuplicateOf
 */
class Document extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    protected $fillable = ['tenant_id', 'category_id', 'title', 'document_on', 'locale', 'source', 'legacy_ref'];

    protected $attributes = [
        'source' => 'upload',
        'status' => 'final',
    ];

    protected function casts(): array
    {
        return [
            'document_on' => 'date:Y-m-d',
            'source' => DocumentSource::class,
            'status' => DocumentStatus::class,
        ];
    }

    /**
     * @return BelongsTo<DocumentCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class);
    }

    /**
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_no');
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /**
     * @return HasMany<DocumentLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(DocumentLink::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function possibleDuplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'possible_duplicate_of_id');
    }

    /**
     * @param  Builder<Document>  $query
     */
    public function scopeLinkedTo(Builder $query, Model $record): void
    {
        $query->whereHas('links', fn (Builder $links) => $links
            ->where('linkable_type', $record->getMorphClass())
            ->where('linkable_id', $record->getKey()));
    }

    /**
     * @param  Builder<Document>  $query
     */
    public function scopeVisibleTo(Builder $query, bool $canSeeSensitive): void
    {
        if (! $canSeeSensitive) {
            $query->whereHas('category', fn (Builder $category) => $category->where('sensitive', false));
        }
    }

    /**
     * Documents of a closed vehicle file or signed documents cannot be changed or deleted.
     */
    public function isLocked(): bool
    {
        if ($this->status->isLocked()) {
            return true;
        }

        return StockCycle::query()
            ->whereIn('id', $this->links()->where('linkable_type', (new StockCycle)->getMorphClass())->select('linkable_id'))
            ->get()
            ->contains(fn (StockCycle $cycle): bool => $cycle->isLocked());
    }
}
