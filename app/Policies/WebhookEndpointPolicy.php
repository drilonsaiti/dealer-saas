<?php

namespace App\Policies;

use App\Domain\Api\Models\WebhookEndpoint;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class WebhookEndpointPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::CompanyManage);
    }

    public function view(User $user, WebhookEndpoint $record): bool
    {
        return $this->allows($user, Permission::CompanyManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CompanyManage);
    }

    public function update(User $user, WebhookEndpoint $record): bool
    {
        return $this->allows($user, Permission::CompanyManage);
    }

    public function delete(User $user, WebhookEndpoint $record): bool
    {
        return false;
    }
}
