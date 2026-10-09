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
    case SalesManage = 'sales.manage';
    case ReportsView = 'reports.view';
    case DocumentsView = 'documents.view';
    case DocumentsManage = 'documents.manage';
    case DocumentsViewSensitive = 'documents.view_sensitive';
    case ImportsManage = 'imports.manage';
    case TemplatesManage = 'templates.manage';
    case InvoicesView = 'invoices.view';
    case InvoicesManage = 'invoices.manage';
    case InvoicesCancel = 'invoices.cancel';
    case PaymentsManage = 'payments.manage';
    case VatView = 'vat.view';
    case VatManage = 'vat.manage';
    case VatClose = 'vat.close';
}
