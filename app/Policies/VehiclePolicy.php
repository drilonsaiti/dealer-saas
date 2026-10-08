<?php

namespace App\Policies;

use App\Domain\Tenancy\Enums\Permission;
use App\Domain\Vehicles\Models\Vehicle;
use App\Models\User;

class VehiclePolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesManage);
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $this->allows($user, Permission::VehiclesManage);
    }

    public function delete(User $user, Vehicle $vehicle): bool
    {
        return false;
    }
}
