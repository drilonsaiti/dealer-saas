<?php

namespace App\Policies;

use App\Domain\Listings\Models\Listing;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class ListingPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function view(User $user, Listing $record): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesManage);
    }

    public function update(User $user, Listing $record): bool
    {
        return $this->allows($user, Permission::VehiclesManage);
    }

    public function delete(User $user, Listing $record): bool
    {
        return false;
    }
}
