<?php

namespace App\Policies;

use App\Domain\Accounting\Models\AccountingExport;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class AccountingExportPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::AccountingExport);
    }

    public function view(User $user, AccountingExport $record): bool
    {
        return $this->allows($user, Permission::AccountingExport);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::AccountingExport);
    }

    public function update(User $user, AccountingExport $record): bool
    {
        return false;
    }

    public function cancel(User $user, AccountingExport $record): bool
    {
        return $this->allows($user, Permission::AccountingExport);
    }

    public function delete(User $user, AccountingExport $record): bool
    {
        return false;
    }
}
