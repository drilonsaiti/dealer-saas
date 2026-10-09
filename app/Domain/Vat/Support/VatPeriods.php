<?php

namespace App\Domain\Vat\Support;

use App\Domain\Vat\Enums\VatPeriodStatus;
use App\Domain\Vat\Models\VatPeriod;
use App\Domain\Vat\Models\VatProfile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Finds the period a tax event belongs to, creating periods on demand from the profile's
 * period length. A date in a closed period never changes it: the event goes into an open
 * correction of that period (eCH-0217 typeOfSubmission 2, which replaces the original return).
 */
class VatPeriods
{
    public function for(Carbon $date, VatProfile $profile): VatPeriod
    {
        $original = VatPeriod::query()
            ->whereNull('corrects_period_id')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first();

        if ($original === null) {
            [$start, $end] = $profile->period->boundsFor($date);

            return VatPeriod::create([
                'vat_profile_id' => $profile->getKey(),
                'starts_on' => $start->toDateString(),
                'ends_on' => $end->toDateString(),
            ]);
        }

        if ($original->status === VatPeriodStatus::Open) {
            return $original;
        }

        $openCorrection = VatPeriod::query()
            ->where('corrects_period_id', $original->getKey())
            ->where('status', VatPeriodStatus::Open->value)
            ->first();

        return $openCorrection ?? VatPeriod::create([
            'vat_profile_id' => $original->vat_profile_id,
            'starts_on' => $original->starts_on->toDateString(),
            'ends_on' => $original->ends_on->toDateString(),
            'corrects_period_id' => $original->getKey(),
        ]);
    }

    /**
     * The original period and its corrections up to and including $period: together they are
     * the full return for those dates.
     *
     * @return list<string>
     */
    public static function chainIds(VatPeriod $period): array
    {
        if ($period->corrects_period_id === null) {
            return [$period->getKey()];
        }

        /** @var Collection<int, VatPeriod> $corrections */
        $corrections = VatPeriod::query()
            ->where('corrects_period_id', $period->corrects_period_id)
            ->where('created_at', '<=', $period->created_at)
            ->get(['id']);

        return [$period->corrects_period_id, ...$corrections->modelKeys()];
    }
}
