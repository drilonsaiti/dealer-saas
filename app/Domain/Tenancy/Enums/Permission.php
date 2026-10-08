<?php

namespace App\Domain\Tenancy\Enums;

/**
 * Abilities checked by policies. Grows module by module (vehicles, sales, VAT, ...).
 */
enum Permission: string
{
    case SettingsView = 'settings.view';
    case CompanyManage = 'company.manage';
    case BankAccountsManage = 'bank_accounts.manage';
    case NumberingManage = 'numbering.manage';
    case MembersManage = 'members.manage';
    case AuditView = 'audit.view';
    case VehiclesView = 'vehicles.view';
    case VehiclesManage = 'vehicles.manage';
    case PartiesView = 'parties.view';
    case PartiesManage = 'parties.manage';
    case PurchasesManage = 'purchases.manage';
    case CostsManage = 'costs.manage';
    case CataloguesManage = 'catalogues.manage';
}
