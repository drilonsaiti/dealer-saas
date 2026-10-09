<?php

namespace App\Domain\Warranty\Actions;

use App\Domain\Sales\Models\Sale;
use App\Domain\Warranty\Enums\WarrantyStatus;
use App\Domain\Warranty\Models\Warranty;
use Illuminate\Support\Carbon;

/**
 * At handover the warranties of the sale start: from the handover date for their duration,
 * from the handover mileage for their km limit. Expired ones are marked daily.
 */
class ActivateWarranties
{
    public function __invoke(Sale $sale, Carbon $on, int $mileage): void
    {
        Warranty::query()->where('sale_id', $sale->getKey())->where('status', WarrantyStatus::Draft->value)->get()
            ->each(fn (Warranty $warranty) => $warranty->forceFill([
                'status' => WarrantyStatus::Active,
                'starts_on' => $on->toDateString(),
                'ends_on' => $on->copy()->addMonthsNoOverflow($warranty->duration_months)->subDay()->toDateString(),
                'km_at_start' => $mileage,
            ])->save());
    }

    public function expire(): int
    {
        return Warranty::query()->where('status', WarrantyStatus::Active->value)->whereDate('ends_on', '<', Carbon::today())
            ->update(['status' => WarrantyStatus::Expired->value, 'updated_at' => now()]);
    }
}
