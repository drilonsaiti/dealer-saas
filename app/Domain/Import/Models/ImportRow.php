<?php

namespace App\Domain\Import\Models;

use App\Domain\Import\Enums\ImportRowAction;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The result of one source row (or one file of a ZIP): what was (or would be) done and why.
 * created_records lists every record the row created, so the run can be rolled back.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $import_run_id
 * @property int $position
 * @property string $row_ref
 * @property array<string, mixed>|null $payload
 * @property ImportRowAction $action
 * @property list<string>|null $messages
 * @property string|null $record_type
 * @property string|null $record_id
 * @property list<array{0: string, 1: string}>|null $created_records
 */
class ImportRow extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'import_run_id', 'position', 'row_ref', 'payload', 'action', 'messages', 'record_type', 'record_id', 'created_records'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'payload' => 'array',
            'action' => ImportRowAction::class,
            'messages' => 'array',
            'created_records' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ImportRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ImportRun::class, 'import_run_id');
    }
}
