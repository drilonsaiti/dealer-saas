<?php

namespace App\Policies;

use App\Domain\Tenancy\Enums\Permission;
use App\Domain\Warranty\Models\Warranty;
use App\Models\User;

class WarrantyPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function view(User $user, Warranty $record): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::SalesManage);
    }

    public function update(User $user, Warranty $record): bool
    {
        return $this->allows($user, Permission::SalesManage);
    }

    public function delete(User $user, Warranty $record): bool
    {
        return false;
    }
}
