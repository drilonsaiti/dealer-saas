<?php

namespace App\Filament\App\Widgets;

use App\Domain\Reporting\StockReport;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;
use App\Support\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dashboard headline figures: stock, stock value, ageing, reservations and open items.
 */
class StockOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasPermission(Permission::ReportsView);
    }

    protected function getColumns(): int
    {
        return 4;
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $report = app(StockReport::class);
        $stock = $report->stock();
        $open = $report->openItems();

        return [
            Stat::make(__('Vehicles in stock'), (string) $stock['count'])
                ->description($stock['average_days'] === null ? null : __('Ø :days days in stock', ['days' => $stock['average_days']]))
                ->icon(Heroicon::OutlinedTruck),
            Stat::make(__('Stock value'), Money::format($stock['value_rp']))
                ->description(__('Purchase prices plus costs so far'))
                ->icon(Heroicon::OutlinedBanknotes),
            Stat::make(__('Over 90 days in stock'), (string) $stock['over_90'])
                ->description(__('over 60 days: :sixty · over 30 days: :thirty', ['sixty' => $stock['over_60'], 'thirty' => $stock['over_30']]))
                ->descriptionIcon($stock['over_90'] > 0 ? Heroicon::OutlinedExclamationTriangle : null)
                ->color($stock['over_90'] > 0 ? 'danger' : 'gray')
                ->icon(Heroicon::OutlinedClock),
            Stat::make(__('Not ready for sale'), (string) ($stock['in_preparation'] + $stock['not_ready']))
                ->description(__('in preparation: :prep · not ready for listing: :not', ['prep' => $stock['in_preparation'], 'not' => $stock['not_ready']]))
                ->icon(Heroicon::OutlinedWrenchScrewdriver),
            Stat::make(__('Reserved'), (string) $stock['reserved'])
                ->description($open['expiring_reservations'] > 0 ? __('expiring within 2 days: :count', ['count' => $open['expiring_reservations']]) : null)
                ->descriptionIcon($open['expiring_reservations'] > 0 ? Heroicon::OutlinedExclamationTriangle : null)
                ->color($open['expiring_reservations'] > 0 ? 'warning' : 'gray')
                ->icon(Heroicon::OutlinedBookmark),
            Stat::make(__('Open promises to customers'), (string) $open['open_promises'])
                ->icon(Heroicon::OutlinedClipboardDocumentCheck),
            Stat::make(__('Sellers not paid yet'), (string) $open['open_seller_payments'])
                ->icon(Heroicon::OutlinedCreditCard),
            Stat::make(__('Files with missing documents'), (string) $open['files_missing_documents'])
                ->color($open['files_missing_documents'] > 0 ? 'warning' : 'gray')
                ->icon(Heroicon::OutlinedDocumentMagnifyingGlass),
        ];
    }
}
