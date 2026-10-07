<?php

namespace App\Models;

use App\Domain\Tenancy\Enums\Permission;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\Email\Concerns\InteractsWithEmailAuthentication;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $locale
 * @property bool $is_platform_admin
 * @property string|null $last_tenant_id
 */
#[Fillable(['name', 'email', 'password', 'locale', 'is_platform_admin', 'last_tenant_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasDefaultTenant, HasEmailAuthentication, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;
    use InteractsWithEmailAuthentication;
    use Notifiable;

    /** @var array<string, Role|null> */
    private array $roleCache = [];

    protected $attributes = [
        'locale' => 'de',
        'is_platform_admin' => false,
        'last_tenant_id' => null,
        'has_email_authentication' => false,
        'app_authentication_secret' => null,
        'app_authentication_recovery_codes' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'has_email_authentication' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Tenant, $this, TenantMembership>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->using(TenantMembership::class)
            ->withPivot(['id', 'role', 'is_active'])
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Tenant, $this, TenantMembership>
     */
    public function activeTenants(): BelongsToMany
    {
        return $this->tenants()
            ->wherePivot('is_active', true)
            ->where('tenants.status', Tenant::STATUS_ACTIVE);
    }

    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'platform' => $this->is_platform_admin,
            'app' => $this->activeTenants()->exists(),
            default => false,
        };
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->activeTenants()->orderBy('tenants.name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->activeTenants()->whereKey($tenant->getKey())->exists();
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        if ($this->last_tenant_id !== null) {
            $last = $this->activeTenants()->whereKey($this->last_tenant_id)->first();

            if ($last !== null) {
                return $last;
            }
        }

        return $this->activeTenants()->orderBy('tenants.name')->first();
    }

    public function roleIn(Tenant|string $tenant): ?Role
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        if (! array_key_exists($tenantId, $this->roleCache)) {
            $membership = $this->memberships()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();

            $this->roleCache[$tenantId] = $membership?->role;
        }

        return $this->roleCache[$tenantId];
    }

    public function currentRole(): ?Role
    {
        $tenantId = app(TenantContext::class)->id();

        return $tenantId === null ? null : $this->roleIn($tenantId);
    }

    public function hasPermission(Permission $permission): bool
    {
        return $this->currentRole()?->allows($permission) ?? false;
    }

    public function forgetRoleCache(): void
    {
        $this->roleCache = [];
    }

    /**
     * Two-factor authentication is required for platform administrators, for anyone with a role
     * that handles money or user access, and for everyone in tenants that demand it.
     */
    public function requiresMultiFactorAuthentication(): bool
    {
        if ($this->is_platform_admin) {
            return true;
        }

        $memberships = $this->memberships()->with('tenant')->where('is_active', true)->get();

        return $memberships->contains(
            fn (TenantMembership $membership): bool => $membership->role->requiresMultiFactorAuthentication()
                || (bool) $membership->tenant?->requiresMultiFactorAuthenticationForAll(),
        );
    }
}
