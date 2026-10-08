<?php

namespace App\Policies;

use App\Domain\Parties\Models\Party;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class PartyPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::PartiesView);
    }

    public function view(User $user, Party $party): bool
    {
        return $this->allows($user, Permission::PartiesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::PartiesManage);
    }

    public function update(User $user, Party $party): bool
    {
        return $this->allows($user, Permission::PartiesManage);
    }

    /**
     * Only parties nothing refers to yet; the database refuses the rest anyway.
     */
    public function delete(User $user, Party $party): bool
    {
        return $this->allows($user, Permission::PartiesManage) && ! $party->isInUse();
    }
}
