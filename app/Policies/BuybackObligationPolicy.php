<?php

namespace App\Policies;

use App\Domain\Financing\Models\BuybackObligation;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class BuybackObligationPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function view(User $user, BuybackObligation $record): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::PurchasesManage);
    }

    public function update(User $user, BuybackObligation $record): bool
    {
        return $this->allows($user, Permission::PurchasesManage);
    }

    public function delete(User $user, BuybackObligation $record): bool
    {
        return false;
    }
}
