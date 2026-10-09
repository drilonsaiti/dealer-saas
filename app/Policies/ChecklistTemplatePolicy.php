<?php

namespace App\Policies;

use App\Domain\Checklists\Models\ChecklistTemplate;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class ChecklistTemplatePolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::SettingsView);
    }

    public function view(User $user, ChecklistTemplate $record): bool
    {
        return $this->allows($user, Permission::SettingsView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CataloguesManage);
    }

    public function update(User $user, ChecklistTemplate $record): bool
    {
        return $this->allows($user, Permission::CataloguesManage);
    }

    public function delete(User $user, ChecklistTemplate $record): bool
    {
        return false;
    }
}
