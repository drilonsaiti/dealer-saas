<?php

namespace App\Filament\App\Widgets;

use App\Domain\Reporting\StockReport;
use App\Domain\Tenancy\Enums\Permission;
use App\Models\User;
use App\Support\Money;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * Margin of the sales per month (sale date), last 12 months. One series on one axis;
 * the number of sales per month is in the tooltip and the totals in the description.
 */
class MarginPerMonthChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    /** @var list<array{month: string, count: int, revenue_rp: int, margin_rp: int, provisional: bool}>|null */
    private ?array $rows = null;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasPermission(Permission::ReportsView);
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Margin per month (CHF)');
    }

    public function getDescription(): string|Htmlable|null
    {
        $rows = $this->rows();
        $count = array_sum(array_column($rows, 'count'));
        $margin = array_sum(array_column($rows, 'margin_rp'));
        $provisional = in_array(true, array_column($rows, 'provisional'), true);

        return __(':count sales in 12 months, margin :margin', ['count' => $count, 'margin' => Money::format($margin)])
            .($provisional ? ' · '.__('partly provisional') : '');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $rows = $this->rows();

        return [
            'datasets' => [[
                'label' => __('Margin'),
                'data' => array_map(fn (array $row): float => $row['margin_rp'] / 100, $rows),
                'sales' => array_column($rows, 'count'),
                'salesLabel' => __('sales'),
                'backgroundColor' => '#2a78d6', // neutral blue: the red brand colour would read as a loss
                'borderRadius' => 4,
                'maxBarThickness' => 32,
            ]],
            'labels' => array_map(fn (array $row): string => Carbon::createFromFormat('Y-m', $row['month'])->translatedFormat('M y'), $rows),
        ];
    }

    protected function getOptions(): RawJs
    {
        // No double quotes in here: the options end up inside an HTML attribute.
        return RawJs::make(<<<'JS'
            {
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => 'CHF ' + ctx.parsed.y.toLocaleString('de-CH', { minimumFractionDigits: 2 }),
                            afterLabel: (ctx) => ctx.dataset.sales[ctx.dataIndex] + ' ' + ctx.dataset.salesLabel,
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { callback: (value) => value.toLocaleString('de-CH') } },
                },
            }
        JS);
    }

    /**
     * @return list<array{month: string, count: int, revenue_rp: int, margin_rp: int, provisional: bool}>
     */
    private function rows(): array
    {
        return $this->rows ??= app(StockReport::class)->salesPerMonth(12);
    }
}
