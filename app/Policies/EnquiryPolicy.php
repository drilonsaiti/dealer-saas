<?php

namespace App\Policies;

use App\Domain\Listings\Models\Enquiry;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

class EnquiryPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function view(User $user, Enquiry $record): bool
    {
        return $this->allows($user, Permission::VehiclesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::SalesManage);
    }

    public function update(User $user, Enquiry $record): bool
    {
        return $this->allows($user, Permission::SalesManage);
    }

    public function delete(User $user, Enquiry $record): bool
    {
        return false;
    }
}
