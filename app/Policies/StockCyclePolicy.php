<?php

namespace App\Policies;

use App\Domain\Tenancy\Enums\Permission;
use App\Domain\Vehicles\Models\StockCycle;
use App\Models\User;

/**
 * Vehicle files are never deleted (a purchase that did not happen is cancelled instead),
 * and archived or cancelled files are read-only.
 */
class StockCyclePolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function view(User $user, StockCycle $cycle): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesManage);
    }

    public function update(User $user, StockCycle $cycle): bool
    {
        return $this->allows($user, Permission::VehiclesManage) && ! $cycle->isLocked();
    }

    /**
     * Change the status (the transition itself checks whether the step is allowed).
     */
    public function transition(User $user, StockCycle $cycle): bool
    {
        return $this->update($user, $cycle);
    }

    public function delete(User $user, StockCycle $cycle): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
