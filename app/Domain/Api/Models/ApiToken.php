<?php

namespace App\Domain\Api\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A dealer's token for the public API (website plugin, own website). Only its SHA-256 is
 * stored; the token itself is shown once. Abilities: listings:read, enquiries:write.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $token_prefix
 * @property string $token_hash
 * @property list<string> $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property-read Tenant $tenant
 */
class ApiToken extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    public const ABILITIES = ['listings:read', 'enquiries:write'];

    protected $fillable = ['tenant_id', 'name', 'token_prefix', 'token_hash', 'abilities', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
