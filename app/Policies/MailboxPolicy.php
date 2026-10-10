<?php

namespace App\Policies;

use App\Domain\Inbox\Models\Mailbox;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class MailboxPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::CompanyManage);
    }

    public function view(User $user, Mailbox $record): bool
    {
        return $this->allows($user, Permission::CompanyManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CompanyManage);
    }

    public function update(User $user, Mailbox $record): bool
    {
        return $this->allows($user, Permission::CompanyManage);
    }

    public function delete(User $user, Mailbox $record): bool
    {
        return false;
    }
}
