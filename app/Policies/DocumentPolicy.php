<?php

namespace App\Policies;

use App\Domain\Documents\Models\Document;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

/**
 * Sensitive categories (ID copies, registration with the previous owner's data, budget
 * calculations) need documents.view_sensitive; locked documents cannot be changed.
 */
class DocumentPolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::DocumentsView);
    }

    public function view(User $user, Document $document): bool
    {
        return $this->allows($user, Permission::DocumentsView)
            && (! $document->category->sensitive || $this->allows($user, Permission::DocumentsViewSensitive));
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::DocumentsManage);
    }

    public function update(User $user, Document $document): bool
    {
        return $this->allows($user, Permission::DocumentsManage) && $this->view($user, $document) && ! $document->isLocked();
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->update($user, $document);
    }
}
