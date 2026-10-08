<?php

namespace App\Domain\Import\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Import\Enums\ImporterType;
use App\Domain\Import\Enums\ImportRunStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One import of one file: checked first (dry run), then imported, and rollable back
 * as long as nothing was built on the imported records.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ImporterType $importer
 * @property string|null $preset_id
 * @property string $file_name
 * @property string $disk
 * @property string $path
 * @property ImportRunStatus $status
 * @property array<string, string|null>|null $mapping
 * @property array<string, mixed>|null $options
 * @property array<string, int>|null $summary
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $created_by
 * @property Carbon $created_at
 */
class ImportRun extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;

    /** @var list<string> */
    public array $auditExclude = ['summary', 'started_at', 'finished_at'];

    protected $fillable = ['tenant_id', 'importer', 'preset_id', 'file_name', 'disk', 'path', 'mapping', 'options', 'created_by'];

    protected $attributes = [
        'status' => 'uploaded',
    ];

    protected function casts(): array
    {
        return [
            'importer' => ImporterType::class,
            'status' => ImportRunStatus::class,
            'mapping' => 'array',
            'options' => 'array',
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<ImportPreset, $this>
     */
    public function preset(): BelongsTo
    {
        return $this->belongsTo(ImportPreset::class, 'preset_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options ?? [], $key, $default);
    }
}
