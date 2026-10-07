<?php

namespace App\Policies;

use App\Domain\Tenancy\Enums\Permission;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Models\User;

class TenantMembershipPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::MembersManage);
    }

    public function view(User $user, TenantMembership $membership): bool
    {
        return $this->allows($user, Permission::MembersManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::MembersManage);
    }

    public function update(User $user, TenantMembership $membership): bool
    {
        return $this->allows($user, Permission::MembersManage);
    }

    public function delete(User $user, TenantMembership $membership): bool
    {
        return $this->allows($user, Permission::MembersManage)
            && $membership->user_id !== $user->getKey();
    }
}
