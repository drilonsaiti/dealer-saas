<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One handover to the accountant: every booking not exported before, up to a date, as a
 * journal file (CSV) in the document archive.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property Carbon $until_on
 * @property int $records_count
 * @property int $entries_count
 * @property int $total_rp
 * @property string|null $document_id
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_by
 * @property Carbon $created_at
 * @property-read Document|null $document
 */
class AccountingExport extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    protected $fillable = ['tenant_id', 'number', 'until_on'];

    protected function casts(): array
    {
        return [
            'until_on' => 'date:Y-m-d',
            'records_count' => 'integer',
            'entries_count' => 'integer',
            'total_rp' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AccountingExportItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(AccountingExportItem::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
