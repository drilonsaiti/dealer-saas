<?php

namespace App\Domain\Import\Models;

use App\Domain\Import\Enums\ImporterType;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved column mapping ("my Excel", "export from tool X"), reused for the next import.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ImporterType $importer
 * @property string $name
 * @property array<string, string|null> $mapping
 * @property array<string, mixed>|null $options
 */
class ImportPreset extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'importer', 'name', 'mapping', 'options'];

    protected function casts(): array
    {
        return [
            'importer' => ImporterType::class,
            'mapping' => 'array',
            'options' => 'array',
        ];
    }
}
