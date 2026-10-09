<?php

namespace App\Policies;

use App\Domain\Tenancy\Enums\Permission;
use App\Domain\Vat\Enums\VatPeriodStatus;
use App\Domain\Vat\Models\VatPeriod;
use App\Models\User;

class VatPeriodPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VatView);
    }

    public function view(User $user, VatPeriod $period): bool
    {
        return $this->allows($user, Permission::VatView);
    }

    /**
     * Periods are created by the tax events, never by hand.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Confirm entries, update from invoices and payments.
     */
    public function update(User $user, VatPeriod $period): bool
    {
        return $period->status === VatPeriodStatus::Open && $this->allows($user, Permission::VatManage);
    }

    /**
     * Release (close), export and record the submission and payment: vat.close.
     */
    public function close(User $user, VatPeriod $period): bool
    {
        return $this->allows($user, Permission::VatClose);
    }

    public function delete(User $user, VatPeriod $period): bool
    {
        return false;
    }
}
