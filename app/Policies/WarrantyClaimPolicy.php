<?php

namespace App\Policies;

use App\Domain\Tenancy\Enums\Permission;
use App\Domain\Warranty\Models\WarrantyClaim;
use App\Models\User;

class WarrantyClaimPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function view(User $user, WarrantyClaim $record): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::SalesManage);
    }

    public function update(User $user, WarrantyClaim $record): bool
    {
        return $this->allows($user, Permission::SalesManage);
    }

    public function delete(User $user, WarrantyClaim $record): bool
    {
        return false;
    }
}
