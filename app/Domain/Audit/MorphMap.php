<?php

namespace App\Domain\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\StatusHistory;
use App\Domain\Parties\Models\Party;
use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Models\TradeIn;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Settings\Models\NumberSequence;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\TyreSet;
use App\Domain\Vehicles\Models\Vehicle;
use App\Models\User;

/**
 * Names stored in polymorphic columns, and how each record type is called in the UI.
 * Every model must be listed here (Laravel enforces it), so new modules add their models.
 */
final class MorphMap
{
    public const MAP = [
        'tenant' => Tenant::class,
        'membership' => TenantMembership::class,
        'user' => User::class,
        'bank_account' => BankAccount::class,
        'number_sequence' => NumberSequence::class,
        'audit_log' => AuditLog::class,
        'status_history' => StatusHistory::class,
        'vehicle' => Vehicle::class,
        'stock_cycle' => StockCycle::class,
        'tyre_set' => TyreSet::class,
        'party' => Party::class,
        'purchase' => Purchase::class,
        'cost_category' => CostCategory::class,
        'cost' => Cost::class,
        'commitment' => Commitment::class,
        'sale' => Sale::class,
        'sale_item' => SaleItem::class,
        'trade_in' => TradeIn::class,
    ];

    public static function label(string $alias): string
    {
        return match ($alias) {
            'tenant' => __('Company'),
            'membership' => __('User'),
            'user' => __('User'),
            'bank_account' => __('Bank account'),
            'number_sequence' => __('Number range'),
            'vehicle' => __('Vehicle'),
            'stock_cycle' => __('Vehicle file'),
            'tyre_set' => __('Tyre set'),
            'party' => __('Contact'),
            'purchase' => __('Purchase'),
            'cost_category' => __('Cost category'),
            'cost' => __('Cost'),
            'commitment' => __('Promise to customer'),
            'sale' => __('Sale'),
            'sale_item' => __('Sale item'),
            'trade_in' => __('Trade-in'),
            default => $alias,
        };
    }
}
