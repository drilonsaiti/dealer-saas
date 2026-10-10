<?php

namespace App\Policies;

use App\Domain\Preparation\Models\ConditionReport;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class ConditionReportPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function view(User $user, ConditionReport $record): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesManage);
    }

    public function update(User $user, ConditionReport $record): bool
    {
        return $this->allows($user, Permission::VehiclesManage);
    }

    public function delete(User $user, ConditionReport $record): bool
    {
        return false;
    }
}
