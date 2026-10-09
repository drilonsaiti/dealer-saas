<?php

namespace App\Policies;

use App\Domain\Tenancy\Enums\Permission;
use App\Domain\Warranty\Models\WarrantyProduct;
use App\Models\User;

class WarrantyProductPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::SettingsView);
    }

    public function view(User $user, WarrantyProduct $record): bool
    {
        return $this->allows($user, Permission::SettingsView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CataloguesManage);
    }

    public function update(User $user, WarrantyProduct $record): bool
    {
        return $this->allows($user, Permission::CataloguesManage);
    }

    public function delete(User $user, WarrantyProduct $record): bool
    {
        return false;
    }
}
