<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A record (invoice, payment, purchase, cost) that went into an export.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $accounting_export_id
 * @property string $source_type
 * @property string $source_id
 */
class AccountingExportItem extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'accounting_export_id', 'source_type', 'source_id'];
}
