<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\TracksAuthors;
use App\Domain\Integrations\Support\Providers;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A dealer's own account at an external service (AutoScout24 …): credentials stay encrypted,
 * the dealer switches it on and off; every call is logged.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $provider
 * @property array<string, string>|null $credentials
 * @property array<string, mixed>|null $settings
 * @property bool $is_active
 * @property string $status
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $last_synced_at
 * @property string|null $last_error
 */
class IntegrationAccount extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUuids;
    use TracksAuthors;

    public const AUTOSCOUT24 = 'autoscout24';

    public const AUTOIDAT = 'autoidat';

    protected $fillable = ['tenant_id', 'provider', 'credentials', 'settings', 'is_active'];

    protected $hidden = ['credentials'];

    protected $attributes = ['status' => 'unchecked', 'is_active' => false];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<IntegrationAccount>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<IntegrationAccount>  $query
     */
    public function scopeOfKind(Builder $query, string $kind): void
    {
        $query->whereIn('provider', Providers::ofKind($kind));
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return filled($value) ? (string) $value : null;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * @return HasMany<IntegrationLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(IntegrationLog::class);
    }
}
