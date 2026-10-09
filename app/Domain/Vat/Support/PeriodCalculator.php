<?php

namespace App\Domain\Vat\Support;

use App\Domain\Vat\Enums\VatCodeKind;
use App\Domain\Vat\Enums\VatMethod;
use App\Domain\Vat\Enums\VatPeriodStatus;
use App\Domain\Vat\Models\TaxEvent;
use App\Domain\Vat\Models\VatPeriod;
use App\Domain\Vat\Rules\InvoiceLineRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The figures of a VAT return from the counting tax events, mapped to the ESTV form fields.
 * A correction contains the full return (original plus corrections), as eCH-0217 requires.
 *
 * Fields: 200 total consideration; 220 exports; 221 supplies abroad; 225 notification
 * procedure; 230 excluded; 235 reductions (credit notes); 280 other; 289 sum of deductions;
 * 299 taxable turnover. Net tax per approved rate on the taxable turnover, 500 payable.
 */
class PeriodCalculator
{
    public const DEDUCTION_FIELDS = [220, 221, 225, 230, 235, 280];

    /**
     * @return array<string, mixed>
     */
    public function __invoke(VatPeriod $period): array
    {
        /** @var Collection<int, TaxEvent> $events */
        $events = TaxEvent::query()->with('netTaxRate')->whereIn('period_id', VatPeriods::chainIds($period))->orderBy('event_on')->get();
        $counting = $events->filter(fn (TaxEvent $e): bool => $e->counts());

        $sum = fn (int $field): int => (int) $counting->where('field', $field)->sum('base_rp');
        $fields = [200 => (int) $counting->whereIn('field', [200, ...array_diff(self::DEDUCTION_FIELDS, [235])])->sum('base_rp')];

        foreach (self::DEDUCTION_FIELDS as $field) {
            $fields[$field] = $field === 235 ? -$sum(235) : $sum($field);
        }

        $fields[289] = array_sum(array_map(fn (int $f): int => $fields[$f], self::DEDUCTION_FIELDS));
        $fields[299] = $fields[200] - $fields[289];

        $rates = $counting->filter(fn (TaxEvent $e): bool => $e->net_tax_rate_id !== null)
            ->groupBy('net_tax_rate_id')
            ->map(function (Collection $group): array {
                /** @var TaxEvent $first */
                $first = $group->first();
                $rate = (string) $first->net_rate;
                $turnover = (int) $group->sum('base_rp');

                return [
                    'net_tax_rate_id' => $first->net_tax_rate_id,
                    'activity' => $first->netTaxRate?->getTranslations('activity') ?? [],
                    'activity_code' => $first->netTaxRate?->activity_code,
                    'rate' => $rate,
                    'turnover_rp' => $turnover,
                    'tax_rp' => (int) round($turnover * (float) $rate / 100),
                ];
            })
            ->sortByDesc('rate')->values()->all();

        $tax = array_sum(array_column($rates, 'tax_rp'));
        $checks = $this->checks($period, $events, $fields, $rates);

        return [
            'fields' => $fields,
            'rates' => $rates,
            'tax_rp' => $tax,
            'payable_rp' => $tax,
            'legal_vat_rp' => (int) $counting->filter(fn (TaxEvent $e): bool => $e->kind === VatCodeKind::Taxable)->sum(fn (TaxEvent $e): int => (int) ($e->explanation['legal_vat_rp'] ?? 0)),
            'event_count' => $counting->count(),
            'open_count' => $events->count() - $counting->count(),
            'checks' => $checks,
            'complete' => $checks === [],
            'rule_versions' => $events->map(fn (TaxEvent $e): string => $e->rule_key.' '.$e->rule_version)->unique()->values()->all(),
            'method' => $period->profile->method->value,
            'basis' => $period->profile->basis->value,
            'calculated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * What stops the return from being closed ("Preview, not complete").
     *
     * @param  Collection<int, TaxEvent>  $events
     * @param  array<int, int>  $fields
     * @param  list<array<string, mixed>>  $rates
     * @return list<array{text: string, params: array<string, mixed>}>
     */
    private function checks(VatPeriod $period, Collection $events, array $fields, array $rates): array
    {
        $checks = [];
        $open = $events->reject(fn (TaxEvent $e): bool => $e->counts());

        if ($open->isNotEmpty()) {
            $checks[] = InvoiceLineRule::step(':count entries must be confirmed or resolved.', ['count' => $open->count()]);
        }

        $unassigned = TaxEvent::query()->whereNull('period_id')->count();

        if ($unassigned > 0) {
            $checks[] = InvoiceLineRule::step(':count entries have no period because the VAT settings are missing.', ['count' => $unassigned]);
        }

        if ($period->profile->method === VatMethod::Effective) {
            $checks[] = InvoiceLineRule::step('The effective method is not released yet.');
        }

        if ($period->status === VatPeriodStatus::Open && $period->ends_on->greaterThanOrEqualTo(Carbon::today())) {
            $checks[] = InvoiceLineRule::step('The period runs until :date.', ['date' => $period->ends_on->format('d.m.Y')]);
        }

        if ($fields[299] !== array_sum(array_column($rates, 'turnover_rp'))) {
            $checks[] = InvoiceLineRule::step('The taxable turnover does not match the turnover per net tax rate.');
        }

        if ($period->corrects_period_id === null && VatPeriod::query()->whereNull('corrects_period_id')->where('status', VatPeriodStatus::Open->value)->whereDate('ends_on', '<', $period->starts_on)->exists()) {
            $checks[] = InvoiceLineRule::step('An earlier period is still open.');
        }

        return $checks;
    }
}
