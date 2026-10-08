<?php

namespace App\Policies;

use App\Domain\Purchasing\Models\Cost;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

/**
 * Confirmed costs are final; costs of closed vehicle files cannot be touched.
 */
class CostPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function view(User $user, Cost $cost): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CostsManage);
    }

    public function update(User $user, Cost $cost): bool
    {
        return $this->allows($user, Permission::CostsManage)
            && ! $cost->isConfirmed()
            && ! ($cost->loadMissing('stockCycle')->stockCycle?->isLocked() ?? false);
    }

    public function confirm(User $user, Cost $cost): bool
    {
        return $this->update($user, $cost);
    }

    public function delete(User $user, Cost $cost): bool
    {
        return $this->update($user, $cost);
    }
}
