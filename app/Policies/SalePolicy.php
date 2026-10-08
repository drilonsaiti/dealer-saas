<?php

namespace App\Policies;

use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

/**
 * Sales are never deleted, only cancelled with a reason.
 */
class SalePolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function view(User $user, Sale $sale): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::SalesManage);
    }

    public function update(User $user, Sale $sale): bool
    {
        return $this->allows($user, Permission::SalesManage)
            && in_array($sale->status, [SaleStatus::Reserved, SaleStatus::Contracted], true);
    }

    public function cancel(User $user, Sale $sale): bool
    {
        return $this->update($user, $sale);
    }

    public function handOver(User $user, Sale $sale): bool
    {
        return $this->allows($user, Permission::SalesManage)
            && in_array($sale->status, [SaleStatus::Contracted, SaleStatus::Invoiced], true);
    }

    public function delete(User $user, Sale $sale): bool
    {
        return false;
    }
}
