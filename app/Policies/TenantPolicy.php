<?php

namespace App\Policies;

use App\Domain\Tenancy\Enums\Permission;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->roleIn($tenant) !== null;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->roleIn($tenant)?->allows(Permission::CompanyManage) ?? false;
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return false;
    }
}
