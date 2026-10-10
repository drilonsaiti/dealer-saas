<?php

namespace App\Policies;

use App\Domain\Preparation\Models\RepairOrder;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class RepairOrderPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function view(User $user, RepairOrder $record): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesManage);
    }

    public function update(User $user, RepairOrder $record): bool
    {
        return $this->allows($user, Permission::VehiclesManage);
    }

    public function delete(User $user, RepairOrder $record): bool
    {
        return false;
    }
}
