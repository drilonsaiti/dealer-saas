<?php

namespace App\Policies;

use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

/**
 * Template versions are never deleted: documents point at the version they were made with.
 */
class DocumentTemplatePolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::TemplatesManage);
    }

    public function view(User $user, DocumentTemplate $template): bool
    {
        return $this->allows($user, Permission::TemplatesManage);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::TemplatesManage);
    }

    public function update(User $user, DocumentTemplate $template): bool
    {
        return $template->isEditable() && $this->allows($user, Permission::TemplatesManage);
    }

    public function delete(User $user, DocumentTemplate $template): bool
    {
        return false;
    }
}
