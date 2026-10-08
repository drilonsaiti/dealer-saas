<?php

namespace App\Filament\Platform\Widgets;

use App\Domain\Operations\Models\RestoreDrill;
use App\Filament\Platform\Resources\RestoreDrills\RestoreDrillResource;
use App\Support\SwissFormat;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Platform dashboard: is the monthly restore drill up to date (acceptance test 13)?
 */
class RestoreDrillStatus extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 2;
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $last = RestoreDrill::lastSuccessful();
        $latest = RestoreDrill::query()->latest('ran_at')->first();
        $overdue = RestoreDrill::isOverdue();

        return [
            Stat::make(__('Last successful restore drill'), $last === null ? __('never') : SwissFormat::date($last->ran_at))
                ->description($overdue
                    ? __('Overdue: run deploy/restore-test.sh (due every month).')
                    : __(':tenants dealers, :tables protected tables restored', ['tenants' => $last->tenants ?? 0, 'tables' => $last->rls_tables ?? 0]))
                ->descriptionIcon($overdue ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($overdue ? 'danger' : 'success')
                ->icon(Heroicon::OutlinedCircleStack)
                ->url(RestoreDrillResource::getUrl()),
            Stat::make(__('Latest drill'), $latest === null ? '–' : ($latest->status === RestoreDrill::STATUS_OK ? __('Successful') : __('Failed')))
                ->description($latest === null ? null : SwissFormat::date($latest->ran_at).' '.$latest->ran_at->format('H:i').($latest->message ? ' · '.$latest->message : ''))
                ->color($latest?->status === RestoreDrill::STATUS_FAILED ? 'danger' : 'gray')
                ->icon(Heroicon::OutlinedClock),
        ];
    }
}
