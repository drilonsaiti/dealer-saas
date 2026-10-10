<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The dealer's own account number for one kind of booking (overrides the Swiss SME default).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $account
 */
class AccountMapping extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    protected $fillable = ['tenant_id', 'key', 'account'];
}
