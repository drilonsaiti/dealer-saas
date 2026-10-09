<?php

namespace App\Policies;

use App\Domain\Payments\Models\BankTransaction;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class BankTransactionPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::PaymentsManage);
    }

    public function view(User $user, BankTransaction $transaction): bool
    {
        return $this->allows($user, Permission::PaymentsManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::PaymentsManage);
    }

    public function update(User $user, BankTransaction $transaction): bool
    {
        return $this->allows($user, Permission::PaymentsManage);
    }

    public function delete(User $user, BankTransaction $transaction): bool
    {
        return false;
    }
}
