<?php

namespace App\Domain\Vat\Actions;

use App\Domain\Vat\Enums\VatMethod;
use App\Domain\Vat\Enums\VatPeriodStatus;
use App\Domain\Vat\Models\TaxEvent;
use App\Domain\Vat\Models\VatNetTaxRate;
use App\Domain\Vat\Models\VatPeriod;
use App\Domain\Vat\Models\VatProfile;
use App\Support\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Saves the dealer's VAT settings from a date on. A change of method, basis or rate is a new
 * profile with a later start date: the previous one ends the day before, and closed periods
 * keep their figures. A profile already used by a closed period cannot be edited.
 */
class SaveVatProfile
{
    /**
     * @param  array<string, mixed>  $data  valid_from, liable, vat_number, method, basis, period, approved_on, notes
     * @param  list<array{id?: string|null, activity: string, activity_code?: string|null, rate: string|float}>  $rates
     */
    public function __invoke(?VatProfile $profile, array $data, array $rates): VatProfile
    {
        $this->guard($profile, $data, $rates);

        return DB::transaction(function () use ($profile, $data, $rates): VatProfile {
            $profile ??= new VatProfile;
            $profile->fill($data)->save();

            $this->closePrevious($profile);
            $this->saveRates($profile, $rates);
            $this->repriceOpenEvents($profile);

            return $profile->load('netTaxRates');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $rates
     */
    private function guard(?VatProfile $profile, array $data, array $rates): void
    {
        $problems = [];
        $from = filled($data['valid_from'] ?? null) ? Carbon::parse($data['valid_from']) : null;

        if ($from === null) {
            $problems[] = __('Enter the date the settings apply from.');
        }

        $method = $data['method'] instanceof VatMethod ? $data['method'] : VatMethod::tryFrom((string) ($data['method'] ?? ''));

        if (($data['liable'] ?? true) && $method === VatMethod::NetTaxRate) {
            if ($rates === []) {
                $problems[] = __('Enter the approved net tax rate.');
            }

            if (count($rates) > 2) {
                $problems[] = __('At most two net tax rates can be approved.');
            }
        }

        foreach ($rates as $rate) {
            if (! is_numeric($rate['rate'] ?? null) || (float) $rate['rate'] <= 0 || (float) $rate['rate'] >= 100) {
                $problems[] = __('A net tax rate must be between 0 and 100 %.');
            }

            if (filled($rate['activity_code'] ?? null) && ! preg_match('/^\d{5}$/', (string) $rate['activity_code'])) {
                $problems[] = __('The ESTV activity code has five digits.');
            }
        }

        if ($profile?->exists && VatPeriod::query()->where('vat_profile_id', $profile->getKey())->where('status', '!=', VatPeriodStatus::Open->value)->exists()) {
            $problems[] = __('These settings are used by a closed VAT period. Add new settings with a later start date instead.');
        }

        $lastClosed = VatPeriod::query()->where('status', '!=', VatPeriodStatus::Open->value)->max('ends_on');

        if ($from !== null && $lastClosed !== null && $from->lessThanOrEqualTo(Carbon::parse($lastClosed))) {
            $problems[] = __('Closed periods are never recalculated: the new settings must start after :date.', ['date' => Carbon::parse($lastClosed)->format('d.m.Y')]);
        }

        if ($from !== null && VatProfile::query()->whereDate('valid_from', $from)->when($profile?->exists, fn ($q) => $q->whereKeyNot($profile->getKey()))->exists()) {
            $problems[] = __('There are already settings from this date.');
        }

        if ($problems !== []) {
            throw BusinessRuleException::because(array_values(array_unique($problems)));
        }
    }

    private function closePrevious(VatProfile $profile): void
    {
        VatProfile::query()
            ->whereKeyNot($profile->getKey())
            ->whereDate('valid_from', '<', $profile->valid_from)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $profile->valid_from))
            ->update(['valid_to' => $profile->valid_from->copy()->subDay()->toDateString()]);

        $next = VatProfile::query()->whereDate('valid_from', '>', $profile->valid_from)->orderBy('valid_from')->value('valid_from');
        $profile->forceFill(['valid_to' => $next === null ? null : Carbon::parse($next)->subDay()->toDateString()])->save();
    }

    /**
     * @param  list<array<string, mixed>>  $rates
     */
    private function saveRates(VatProfile $profile, array $rates): void
    {
        $keep = [];

        foreach ($rates as $i => $rate) {
            $activity = $rate['activity'];
            $model = filled($rate['id'] ?? null) ? $profile->netTaxRates()->find($rate['id']) : null;
            $model ??= new VatNetTaxRate(['vat_profile_id' => $profile->getKey()]);
            $model->fill([
                'activity' => is_array($activity) ? $activity : array_fill_keys((array) config('dealer.locales'), (string) $activity),
                'activity_code' => filled($rate['activity_code'] ?? null) ? (string) $rate['activity_code'] : null,
                'rate' => (string) $rate['rate'],
                'sort' => $i + 1,
            ])->save();
            $keep[] = $model->getKey();
        }

        $profile->netTaxRates()->whereNotIn('id', $keep)->get()->each(function (VatNetTaxRate $rate): void {
            if (TaxEvent::query()->where('net_tax_rate_id', $rate->getKey())->exists()) {
                throw new BusinessRuleException(__('The net tax rate :rate % is used by booked entries and cannot be removed.', ['rate' => rtrim(rtrim((string) $rate->rate, '0'), '.')]));
            }

            $rate->delete();
        });
    }

    /**
     * Entries of open periods follow a corrected rate; closed periods keep theirs.
     */
    private function repriceOpenEvents(VatProfile $profile): void
    {
        foreach ($profile->netTaxRates()->get() as $rate) {
            TaxEvent::query()
                ->where('net_tax_rate_id', $rate->getKey())
                ->where('net_rate', '!=', $rate->rate)
                ->whereHas('period', fn ($q) => $q->where('status', VatPeriodStatus::Open->value))
                ->get()
                ->each(fn (TaxEvent $event) => $event->forceFill([
                    'net_rate' => $rate->rate,
                    'tax_rp' => (int) round($event->base_rp * (float) $rate->rate / 100),
                ])->save());
        }
    }
}
