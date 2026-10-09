<?php

namespace App\Domain\Vat\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Carbon;

enum VatPeriodLength: string implements HasLabel
{
    case Month = 'month';
    case Quarter = 'quarter';
    case HalfYear = 'half_year';
    case Year = 'year';

    public function getLabel(): string
    {
        return match ($this) {
            self::Month => __('Monthly'),
            self::Quarter => __('Quarterly'),
            self::HalfYear => __('Half-yearly'),
            self::Year => __('Yearly'),
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon} first and last day of the period containing $date
     */
    public function boundsFor(Carbon $date): array
    {
        $months = match ($this) {
            self::Month => 1,
            self::Quarter => 3,
            self::HalfYear => 6,
            self::Year => 12,
        };
        $startMonth = intdiv($date->month - 1, $months) * $months + 1;
        $start = Carbon::create($date->year, $startMonth, 1)->startOfDay();

        return [$start, $start->copy()->addMonths($months)->subDay()];
    }
}
