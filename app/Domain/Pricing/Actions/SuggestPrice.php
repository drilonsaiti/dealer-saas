<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Listings\Actions\SaveListing;
use App\Domain\Listings\Models\Listing;
use App\Domain\Pricing\Support\PriceSuggestion;
use App\Domain\Reporting\CalculateMargin;
use App\Domain\Tenancy\TenantContext;
use App\Domain\VehicleData\Models\VehicleValuation;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Price suggestion for a car on sale, from the dealer's own rules: the longer it stands, the
 * lower (steps by days in stock on the planned price), not above the market value when one is
 * known, never below what the car cost plus a minimum margin. Rounded down to CHF 100.
 * A suggestion only — the dealer applies it with one click.
 */
class SuggestPrice
{
    /** @var list<array{days: int, percent: float}> */
    public const DEFAULT_STEPS = [
        ['days' => 45, 'percent' => 3.0],
        ['days' => 60, 'percent' => 5.0],
        ['days' => 90, 'percent' => 8.0],
        ['days' => 120, 'percent' => 12.0],
    ];

    public const DEFAULT_MIN_MARGIN_RP = 50_000;

    /** A market value is a cap only when the price is clearly above it. */
    private const MARKET_TOLERANCE = 1.03;

    public function __construct(private readonly CalculateMargin $margin) {}

    public function __invoke(StockCycle $cycle): ?PriceSuggestion
    {
        if (! in_array($cycle->status, [StockCycleStatus::ReadyForSale, StockCycleStatus::Listed], true) || ($cycle->list_price_rp ?? 0) <= 0) {
            return null;
        }

        $current = (int) $cycle->list_price_rp;
        $reference = max($current, (int) ($cycle->planned_price_rp ?? 0));
        $days = $cycle->daysInStock();
        $reasons = [];
        $target = $current;

        $step = collect(self::steps())->filter(fn (array $s): bool => $days !== null && $days >= $s['days'])->last();

        if ($step !== null) {
            $target = (int) round($reference * (1 - $step['percent'] / 100));
            $reasons[] = __(':days days in stock: −:percent % on :reference.', ['days' => $days, 'percent' => rtrim(rtrim(number_format($step['percent'], 1, '.', ''), '0'), '.'), 'reference' => Money::format($reference)]);
        }

        $valuation = VehicleValuation::query()->where('stock_cycle_id', $cycle->getKey())->whereNotNull('retail_rp')->latest('valued_on')->latest('created_at')->first();

        if ($valuation !== null && $current > $valuation->retail_rp * self::MARKET_TOLERANCE) {
            $target = min($target, (int) $valuation->retail_rp);
            $reasons[] = __('Market value :value (:date) is below the price.', ['value' => Money::format((int) $valuation->retail_rp), 'date' => $valuation->valued_on->format('d.m.Y')]);
        }

        $floor = $this->margin->__invoke($cycle)->landedCostRp() + self::minMarginRp();
        $target = intdiv(max(0, $target), 10_000) * 10_000; // down to CHF 100

        if ($target < $floor) {
            $target = (int) (ceil($floor / 10_000) * 10_000);
            $reasons[] = __('Not below cost plus minimum margin (:floor).', ['floor' => Money::format($floor)]);
        }

        if ($target >= $current) {
            return new PriceSuggestion($current, $current, $floor, $days, $reasons === [] ? [__('The price fits the rules.')] : $reasons);
        }

        return new PriceSuggestion($current, $target, $floor, $days, $reasons);
    }

    /**
     * Sets the suggested price as list price and on the advert (portals follow).
     */
    public function apply(StockCycle $cycle, int $priceRp): StockCycle
    {
        if ($priceRp <= 0) {
            throw new BusinessRuleException(__('Enter the price.'));
        }

        return DB::transaction(function () use ($cycle, $priceRp): StockCycle {
            $cycle->forceFill(['list_price_rp' => $priceRp])->save();

            if (Listing::query()->where('stock_cycle_id', $cycle->getKey())->exists()) {
                app(SaveListing::class)($cycle, ['price_rp' => $priceRp]);
            }

            return $cycle->refresh();
        });
    }

    /**
     * @return list<array{days: int, percent: float}>
     */
    public static function steps(): array
    {
        $steps = app(TenantContext::class)->tenant()?->setting('pricing.steps');

        if (! is_array($steps) || $steps === []) {
            return self::DEFAULT_STEPS;
        }

        $clean = array_values(array_filter(array_map(fn ($s): ?array => is_array($s) && is_numeric($s['days'] ?? null) && is_numeric($s['percent'] ?? null)
            ? ['days' => (int) $s['days'], 'percent' => (float) $s['percent']] : null, $steps)));
        usort($clean, fn (array $a, array $b): int => $a['days'] <=> $b['days']);

        return $clean === [] ? self::DEFAULT_STEPS : $clean;
    }

    public static function minMarginRp(): int
    {
        $value = app(TenantContext::class)->tenant()?->setting('pricing.min_margin_rp');

        return is_numeric($value) ? (int) $value : self::DEFAULT_MIN_MARGIN_RP;
    }
}
