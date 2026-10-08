<?php

namespace App\Policies;

use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class CommitmentPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function view(User $user, Commitment $commitment): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesManage);
    }

    public function update(User $user, Commitment $commitment): bool
    {
        return $this->allows($user, Permission::VehiclesManage) && ! $commitment->stockCycle->isLocked();
    }

    public function delete(User $user, Commitment $commitment): bool
    {
        return $this->update($user, $commitment) && ! $commitment->isDone();
    }
}
