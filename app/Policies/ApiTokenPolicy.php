<?php

namespace App\Policies;

use App\Domain\Api\Models\ApiToken;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class ApiTokenPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::CompanyManage);
    }

    public function view(User $user, ApiToken $record): bool
    {
        return $this->allows($user, Permission::CompanyManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CompanyManage);
    }

    public function update(User $user, ApiToken $record): bool
    {
        return $this->allows($user, Permission::CompanyManage);
    }

    public function delete(User $user, ApiToken $record): bool
    {
        return false;
    }
}
