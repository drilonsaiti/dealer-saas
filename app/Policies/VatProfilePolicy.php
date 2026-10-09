<?php

namespace App\Policies;

use App\Domain\Tenancy\Enums\Permission;
use App\Domain\Vat\Models\VatProfile;
use App\Models\User;

class VatProfilePolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VatView);
    }

    public function view(User $user, VatProfile $profile): bool
    {
        return $this->allows($user, Permission::VatView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::VatManage);
    }

    public function update(User $user, VatProfile $profile): bool
    {
        return $this->allows($user, Permission::VatManage);
    }

    /**
     * Settings are dated history; a change is a new profile.
     */
    public function delete(User $user, VatProfile $profile): bool
    {
        return false;
    }
}
