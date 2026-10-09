<?php

namespace App\Policies;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;

/**
 * Drafts can be changed and deleted; issued invoices only credited (invoices.cancel).
 */
class InvoicePolicy
{
    use ChecksTenantPermissions;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::InvoicesView);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->allows($user, Permission::InvoicesView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::InvoicesManage);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Draft && $this->allows($user, Permission::InvoicesManage);
    }

    public function issue(User $user, Invoice $invoice): bool
    {
        return $this->update($user, $invoice);
    }

    public function credit(User $user, Invoice $invoice): bool
    {
        return $invoice->status->isIssued() && $invoice->status !== InvoiceStatus::Cancelled && $this->allows($user, Permission::InvoicesCancel);
    }

    public function send(User $user, Invoice $invoice): bool
    {
        return $invoice->status->isIssued() && $this->allows($user, Permission::InvoicesManage);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->update($user, $invoice);
    }
}
