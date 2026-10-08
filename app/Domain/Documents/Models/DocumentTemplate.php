<?php

namespace App\Domain\Documents\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Documents\Enums\TemplateStatus;
use App\Domain\Documents\Enums\TemplateType;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One version of a dealer's template for a generated document: the contract clauses and the
 * footer in every language. Only drafts can be edited; activating a draft retires the previous
 * version, and documents keep the version they were made with.
 *
 * @property string $id
 * @property string $tenant_id
 * @property TemplateType $type_key
 * @property int $version
 * @property TemplateStatus $status
 * @property Carbon|null $valid_from
 * @property array<string, list<string>> $clauses
 * @property array<string, string|null>|null $footer
 * @property string|null $notes
 */
class DocumentTemplate extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    protected $fillable = ['tenant_id', 'type_key', 'version', 'valid_from', 'clauses', 'footer', 'notes'];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'type_key' => TemplateType::class,
            'status' => TemplateStatus::class,
            'version' => 'integer',
            'valid_from' => 'date:Y-m-d',
            'clauses' => 'array',
            'footer' => 'array',
        ];
    }

    /**
     * @param  Builder<DocumentTemplate>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', TemplateStatus::Active->value);
    }

    public function isEditable(): bool
    {
        return $this->status === TemplateStatus::Draft;
    }

    /**
     * @return list<string>
     */
    public function clausesIn(string $locale): array
    {
        return array_values(array_filter($this->clauses[$locale] ?? [], fn (string $clause): bool => trim($clause) !== ''));
    }

    public function footerIn(string $locale): ?string
    {
        $footer = $this->footer[$locale] ?? null;

        return filled($footer) ? (string) $footer : null;
    }

    /**
     * Languages without any clause; a version can only be activated when this is empty.
     *
     * @return list<string>
     */
    public function missingLocales(): array
    {
        return array_values(array_filter(
            (array) config('dealer.locales'),
            fn (string $locale): bool => $this->clausesIn($locale) === [],
        ));
    }
}
