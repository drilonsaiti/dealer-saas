<?php

namespace App\Domain\Portal\Models;

use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Sales\Models\Sale;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $sale_id
 * @property string $token_prefix
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 * @property Carbon $created_at
 * @property-read Sale $sale
 */
class PortalLink extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    public const PREFIX = 'cp_';

    protected $fillable = ['tenant_id', 'sale_id', 'token_prefix', 'token_hash', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
