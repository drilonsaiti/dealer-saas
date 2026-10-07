<?php

namespace App\Policies;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

/**
 * The audit log can be read by those allowed to, and never changed by anyone.
 */
class AuditLogPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::AuditView);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $this->allows($user, Permission::AuditView);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $auditLog): bool
    {
        return false;
    }
}
