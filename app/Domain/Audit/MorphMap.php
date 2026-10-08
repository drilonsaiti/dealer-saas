<?php

namespace App\Domain\Audit;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\StatusHistory;
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
            default => $alias,
        };
    }
}
