<?php

namespace App\Policies;

use App\Domain\Tenancy\Enums\Permission;
use App\Domain\Vehicles\Models\TyreSet;
use App\Models\User;

class TyreSetPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function view(User $user, TyreSet $tyreSet): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesManage);
    }

    public function update(User $user, TyreSet $tyreSet): bool
    {
        return $this->allows($user, Permission::VehiclesManage);
    }

    public function delete(User $user, TyreSet $tyreSet): bool
    {
        return $this->allows($user, Permission::VehiclesManage);
    }
}
