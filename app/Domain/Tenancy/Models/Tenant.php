<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Settings\Models\NumberSequence;
use App\Domain\Tenancy\Enums\Role;
use App\Models\User;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;

/**
 * A dealer company using the platform. All business data belongs to exactly one tenant.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $legal_name
 * @property string|null $uid
 * @property string|null $vat_number
 * @property string|null $street
 * @property string|null $zip
 * @property string|null $city
 * @property string $country
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $website
 * @property string $default_locale
 * @property string|null $brand_color
 * @property array<string, mixed>|null $settings
 * @property string $status
 */
#[UseFactory(TenantFactory::class)]
class Tenant extends Model
{
    use Auditable;

    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const DEFAULT_IDLE_TIMEOUT_MINUTES = 30;

    protected $fillable = [
        'name',
        'slug',
        'legal_name',
        'uid',
        'vat_number',
        'street',
        'zip',
        'city',
        'country',
        'phone',
        'email',
        'website',
        'default_locale',
        'brand_color',
        'settings',
        'status',
    ];

    protected $attributes = [
        'country' => 'CH',
        'default_locale' => 'de',
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<User, $this, TenantMembership>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->using(TenantMembership::class)
            ->withPivot(['id', 'role', 'is_active'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    /**
     * @return HasMany<BankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    /**
     * @return HasMany<NumberSequence, $this>
     */
    public function numberSequences(): HasMany
    {
        return $this->hasMany(NumberSequence::class);
    }

    /**
     * Every audit entry of this dealer (the Auditable trait's auditLogs() only covers the tenant record itself).
     *
     * @return HasMany<AuditLog, $this>
     */
    public function allAuditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->settings ?? [], $key, $default);
    }

    public function requiresMultiFactorAuthenticationForAll(): bool
    {
        return (bool) $this->setting('security.require_mfa_for_all', false);
    }

    public function idleTimeoutMinutes(): int
    {
        return max(5, (int) $this->setting('security.idle_timeout_minutes', self::DEFAULT_IDLE_TIMEOUT_MINUTES));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function addMember(User $user, Role $role): TenantMembership
    {
        /** @var TenantMembership */
        return $this->memberships()->updateOrCreate(
            ['user_id' => $user->getKey()],
            ['role' => $role, 'is_active' => true],
        );
    }
}
