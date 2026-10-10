<?php

namespace App\Policies;

use App\Domain\Chat\Models\ChatMessage;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class ChatMessagePolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::EmailManage);
    }

    public function view(User $user, ChatMessage $record): bool
    {
        return $this->allows($user, Permission::EmailManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::EmailManage);
    }

    public function update(User $user, ChatMessage $record): bool
    {
        return $this->allows($user, Permission::EmailManage);
    }

    public function delete(User $user, ChatMessage $record): bool
    {
        return false;
    }
}
