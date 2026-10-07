<?php

namespace App\Policies;

use App\Domain\Settings\Models\NumberSequence;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

/**
 * The set of sequences is fixed per dealer; they can be edited but not created or deleted.
 */
class NumberSequencePolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::SettingsView);
    }

    public function view(User $user, NumberSequence $sequence): bool
    {
        return $this->allows($user, Permission::SettingsView);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, NumberSequence $sequence): bool
    {
        return $this->allows($user, Permission::NumberingManage);
    }

    public function delete(User $user, NumberSequence $sequence): bool
    {
        return false;
    }
}
