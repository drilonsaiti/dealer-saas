<?php

namespace App\Policies;

use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class IntegrationAccountPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::CompanyManage);
    }

    public function view(User $user, IntegrationAccount $record): bool
    {
        return $this->allows($user, Permission::CompanyManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CompanyManage);
    }

    public function update(User $user, IntegrationAccount $record): bool
    {
        return $this->allows($user, Permission::CompanyManage);
    }

    public function delete(User $user, IntegrationAccount $record): bool
    {
        return false;
    }
}
