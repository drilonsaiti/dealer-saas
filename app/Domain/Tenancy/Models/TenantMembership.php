<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Tenancy\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Validation\ValidationException;

/**
 * A user's membership in a tenant, with the role they have there.
 *
 * Not tenant-scoped by a global scope on purpose: login and the tenant switcher must
 * see all memberships of the signed-in user. Screens that list members are scoped to
 * the current tenant by Filament's tenancy and by explicit where-clauses.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property Role $role
 * @property bool $is_active
 */
class TenantMembership extends Pivot
{
    use Auditable;
    use HasUuids;

    protected $table = 'tenant_user';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // A dealer must always keep at least one active administrator.
        static::updating(function (TenantMembership $membership): void {
            $wasAdmin = $membership->getOriginal('role') === Role::Administrator && (bool) $membership->getOriginal('is_active');
            $isAdmin = $membership->role === Role::Administrator && $membership->is_active;

            if ($wasAdmin && ! $isAdmin) {
                $membership->ensureAnotherAdministratorExists();
            }
        });

        static::deleting(function (TenantMembership $membership): void {
            // Use the stored values, not unsaved changes on this instance.
            if ($membership->getOriginal('role') === Role::Administrator && (bool) $membership->getOriginal('is_active')) {
                $membership->ensureAnotherAdministratorExists();
            }
        });
    }

    private function ensureAnotherAdministratorExists(): void
    {
        $others = static::query()
            ->where('tenant_id', $this->tenant_id)
            ->whereKeyNot($this->getKey())
            ->where('role', Role::Administrator->value)
            ->where('is_active', true)
            ->exists();

        if (! $others) {
            throw ValidationException::withMessages([
                'role' => __('Every dealer needs at least one active administrator.'),
            ]);
        }
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
