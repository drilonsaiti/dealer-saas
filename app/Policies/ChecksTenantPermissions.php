<?php

namespace App\Policies;

use App\Domain\Tenancy\Enums\Permission;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

trait ChecksTenantPermissions
{
    /**
     * Platform administrators working in the platform panel (isolation bypassed) may do anything.
     */
    public function before(User $user): ?bool
    {
        if ($user->is_platform_admin && app(TenantContext::class)->isBypassed()) {
            return true;
        }

        return null;
    }

    protected function allows(User $user, Permission $permission): bool
    {
        return $user->hasPermission($permission);
    }

    protected function isMember(User $user): bool
    {
        return $user->currentRole() !== null;
    }
}
