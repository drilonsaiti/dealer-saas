<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Role of a user inside one tenant (dealer). A user can have different roles in different tenants.
 */
enum Role: string implements HasLabel
{
    case Administrator = 'administrator';
    case Sales = 'sales';
    case Accounting = 'accounting';
    case ReadOnly = 'read_only';

    public function getLabel(): string
    {
        return match ($this) {
            self::Administrator => __('Administrator'),
            self::Sales => __('Sales'),
            self::Accounting => __('Accounting'),
            self::ReadOnly => __('Read-only'),
        };
    }

    public function allows(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Administrator => Permission::cases(),
            self::Accounting => [
                Permission::SettingsView,
                Permission::BankAccountsManage,
                Permission::NumberingManage,
                Permission::AuditView,
                Permission::VehiclesView,
                Permission::PartiesView,
                Permission::PartiesManage,
                Permission::PurchasesManage,
                Permission::CostsManage,
                Permission::CataloguesManage,
                Permission::ReportsView,
                Permission::DocumentsView,
                Permission::DocumentsManage,
                Permission::DocumentsViewSensitive,
                Permission::InvoicesView,
                Permission::InvoicesManage,
                Permission::InvoicesCancel,
                Permission::PaymentsManage,
            ],
            self::Sales => [
                Permission::SettingsView,
                Permission::VehiclesView,
                Permission::VehiclesManage,
                Permission::PartiesView,
                Permission::PartiesManage,
                Permission::PurchasesManage,
                Permission::CostsManage,
                Permission::SalesManage,
                Permission::DocumentsView,
                Permission::DocumentsManage,
                Permission::InvoicesView,
                Permission::InvoicesManage,
            ],
            self::ReadOnly => [
                Permission::VehiclesView,
                Permission::PartiesView,
                Permission::ReportsView,
                Permission::DocumentsView,
                Permission::InvoicesView,
            ],
        };
    }

    /**
     * Roles that handle money or user access must use two-factor authentication.
     */
    public function requiresMultiFactorAuthentication(): bool
    {
        return in_array($this, [self::Administrator, self::Accounting], true);
    }
}
