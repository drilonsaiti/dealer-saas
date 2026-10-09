<?php

namespace App\Filament\App\Widgets;

use App\Domain\Financing\Enums\BuybackStatus;
use App\Domain\Financing\Models\BuybackObligation;
use App\Domain\Financing\Models\Financing;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Reporting\StockReport;
use App\Domain\Tenancy\Enums\Permission;
use App\Domain\Warranty\Models\Warranty;
use App\Filament\App\Resources\BuybackObligations\BuybackObligationResource;
use App\Filament\App\Resources\Financings\FinancingResource;
use App\Filament\App\Resources\Invoices\InvoiceResource;
use App\Filament\App\Resources\Warranties\WarrantyResource;
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
            ...$this->invoiceStats(),
            ...$this->leasingWarrantyStats(),
        ];
    }

    /**
     * @return list<Stat>
     */
    private function invoiceStats(): array
    {
        if (! (auth()->user()?->can('viewAny', Invoice::class) ?? false)) {
            return [];
        }

        $open = Invoice::query()->open()->get();
        $overdue = $open->filter(fn (Invoice $invoice): bool => $invoice->isOverdue());

        return [
            Stat::make(__('Open invoices'), Money::format((int) $open->sum(fn (Invoice $invoice): int => $invoice->openRp())))
                ->description(trans_choice(':count invoice|:count invoices', $open->count()).($overdue->isNotEmpty() ? ' · '.__(':count overdue', ['count' => $overdue->count()]) : ''))
                ->descriptionIcon($overdue->isNotEmpty() ? Heroicon::OutlinedExclamationTriangle : null)
                ->color($overdue->isNotEmpty() ? 'danger' : 'gray')
                ->icon(Heroicon::OutlinedBanknotes)
                ->url(InvoiceResource::getUrl('index')),
        ];
    }

    /**
     * Open leasing payouts (with the oldest age), open buy-back obligations (contingent
     * liability) and warranties ending within 30 days.
     *
     * @return list<Stat>
     */
    private function leasingWarrantyStats(): array
    {
        if (! (auth()->user()?->can('viewAny', Financing::class) ?? false)) {
            return [];
        }

        $payouts = Financing::query()->awaitingPayout()->get();
        $oldest = $payouts->map(fn (Financing $f): ?int => FinancingResource::payoutAge($f))->filter()->max();
        $buybacks = BuybackObligation::query()->where('status', BuybackStatus::Open->value);
        $buybackSum = (int) (clone $buybacks)->sum('amount_rp');
        $buybackSoon = (clone $buybacks)->whereDate('remind_on', '<=', today())->count();
        $expiring = Warranty::query()->expiringWithin(30)->count();

        return [
            Stat::make(__('Open leasing payouts'), Money::format((int) $payouts->sum('payout_expected_rp')))
                ->description(trans_choice(':count financing|:count financings', $payouts->count()).($oldest !== null ? ' · '.__('oldest :days days', ['days' => $oldest]) : ''))
                ->color($oldest !== null && $oldest > 10 ? 'warning' : 'gray')
                ->icon(Heroicon::OutlinedBuildingLibrary)
                ->url(FinancingResource::getUrl('index')),
            Stat::make(__('Buy-back obligations'), Money::format($buybackSum))
                ->description($buybackSoon > 0 ? __('due within 3 months: :count', ['count' => $buybackSoon]) : __('contingent liability'))
                ->color($buybackSoon > 0 ? 'warning' : 'gray')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->url(BuybackObligationResource::getUrl('index')),
            Stat::make(__('Warranties ending soon'), (string) $expiring)
                ->description(__('within 30 days'))
                ->color($expiring > 0 ? 'warning' : 'gray')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->url(WarrantyResource::getUrl('index')),
        ];
    }
}
