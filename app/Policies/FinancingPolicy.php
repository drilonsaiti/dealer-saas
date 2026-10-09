<?php

namespace App\Policies;

use App\Domain\Financing\Models\Financing;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class FinancingPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function view(User $user, Financing $record): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::SalesManage);
    }

    public function update(User $user, Financing $record): bool
    {
        return $this->allows($user, Permission::SalesManage);
    }

    public function delete(User $user, Financing $record): bool
    {
        return false;
    }
}
