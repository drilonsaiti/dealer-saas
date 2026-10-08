<?php

namespace App\Policies;

use App\Domain\Import\Models\ImportRun;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

/**
 * Imports change a lot of data at once: administrators only.
 */
class ImportRunPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ImportsManage);
    }

    public function view(User $user, ImportRun $run): bool
    {
        return $this->allows($user, Permission::ImportsManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ImportsManage);
    }

    public function update(User $user, ImportRun $run): bool
    {
        return $this->allows($user, Permission::ImportsManage);
    }

    public function delete(User $user, ImportRun $run): bool
    {
        return false;
    }
}
