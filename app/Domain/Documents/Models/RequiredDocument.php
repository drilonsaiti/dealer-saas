<?php

namespace App\Domain\Documents\Models;

use App\Domain\Documents\Enums\RequiredDocumentStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A manual status of a required document on a vehicle file ("requested", "not required").
 * "Present" is never stored: it is computed from the documents actually in the file.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $stock_cycle_id
 * @property string $category_key
 * @property RequiredDocumentStatus $status
 * @property string|null $note
 */
class RequiredDocument extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['tenant_id', 'stock_cycle_id', 'category_key', 'status', 'note'];

    protected function casts(): array
    {
        return [
            'status' => RequiredDocumentStatus::class,
        ];
    }
}
