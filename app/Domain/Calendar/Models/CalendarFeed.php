<?php

namespace App\Domain\Calendar\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's secret calendar link for one dealer (only the hash is stored).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $token_prefix
 * @property string $token_hash
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read Tenant $tenant
 */
class CalendarFeed extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const PREFIX = 'cal_';

    protected $fillable = ['tenant_id', 'user_id', 'token_prefix', 'token_hash'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
