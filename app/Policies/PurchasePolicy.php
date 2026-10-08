<?php

namespace App\Policies;

use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class PurchasePolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::PurchasesManage);
    }

    public function update(User $user, Purchase $purchase): bool
    {
        return $this->allows($user, Permission::PurchasesManage) && ! $purchase->stockCycle->isLocked();
    }

    public function delete(User $user, Purchase $purchase): bool
    {
        return false;
    }
}
