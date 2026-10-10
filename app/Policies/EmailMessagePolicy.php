<?php

namespace App\Policies;

use App\Domain\Inbox\Enums\EmailStatus;
use App\Domain\Inbox\Models\EmailMessage;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class EmailMessagePolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::EmailManage);
    }

    public function view(User $user, EmailMessage $record): bool
    {
        return $this->allows($user, Permission::EmailManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::EmailManage);
    }

    public function update(User $user, EmailMessage $record): bool
    {
        return $this->allows($user, Permission::EmailManage);
    }

    /** Only unsent drafts can be thrown away; received and sent mail stays. */
    public function delete(User $user, EmailMessage $record): bool
    {
        return $record->status === EmailStatus::Draft && $this->allows($user, Permission::EmailManage);
    }
}
