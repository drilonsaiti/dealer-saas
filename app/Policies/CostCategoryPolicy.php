<?php

namespace App\Policies;

use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

/**
 * Categories are deactivated, not deleted: existing costs keep pointing at them.
 */
class CostCategoryPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::CataloguesManage);
    }

    public function view(User $user, CostCategory $category): bool
    {
        return $this->allows($user, Permission::CataloguesManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CataloguesManage);
    }

    public function update(User $user, CostCategory $category): bool
    {
        return $this->allows($user, Permission::CataloguesManage);
    }

    public function delete(User $user, CostCategory $category): bool
    {
        return false;
    }
}
