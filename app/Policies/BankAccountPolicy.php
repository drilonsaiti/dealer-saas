<?php

namespace App\Policies;

use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class BankAccountPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::SettingsView);
    }

    public function view(User $user, BankAccount $bankAccount): bool
    {
        return $this->allows($user, Permission::SettingsView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::BankAccountsManage);
    }

    public function update(User $user, BankAccount $bankAccount): bool
    {
        return $this->allows($user, Permission::BankAccountsManage);
    }

    public function delete(User $user, BankAccount $bankAccount): bool
    {
        return $this->allows($user, Permission::BankAccountsManage);
    }
}
