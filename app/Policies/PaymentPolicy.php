<?php

namespace App\Policies;

use App\Domain\Payments\Models\Payment;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class PaymentPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::InvoicesView);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->allows($user, Permission::InvoicesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::PaymentsManage);
    }

    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $this->allows($user, Permission::PaymentsManage);
    }
}
