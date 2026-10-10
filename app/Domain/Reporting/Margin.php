<?php

namespace App\Domain\Reporting;

/**
 * Contribution margin of one vehicle file (spec 9.7, net tax rate method): gross sale
 * revenue − gross purchase price − gross costs (incl. open promises at their estimate).
 * It is not company profit, and "provisional" until every cost is confirmed and the car is sold.
 * With the net tax rate method the net tax on the revenue is shown separately and deducted for
 * the margin after VAT.
 */
final readonly class Margin
{
    public const BASIS_SALE = 'sale';

    public const BASIS_LIST_PRICE = 'list_price';

    public const BASIS_PLANNED_PRICE = 'planned_price';

    public const BASIS_NONE = 'none';

    public function __construct(
        public int $revenueRp,
        public string $revenueBasis,
        public int $purchaseRp,
        public int $confirmedCostsRp,
        public int $openCostsRp,
        public int $openPromisesRp,
        public bool $isProvisional,
        public ?int $netTaxRp = null,
        public ?string $netTaxRate = null,
        public int $openRepairsRp = 0,
    ) {}

    public function costsRp(): int
    {
        return $this->confirmedCostsRp + $this->openCostsRp + $this->openPromisesRp + $this->openRepairsRp;
    }

    /**
     * Purchase price plus all costs: what the car has cost the dealer so far.
     */
    public function landedCostRp(): int
    {
        return $this->purchaseRp + $this->costsRp();
    }

    public function marginRp(): ?int
    {
        return $this->revenueBasis === self::BASIS_NONE ? null : $this->revenueRp - $this->landedCostRp();
    }

    /**
     * Contribution after the net tax owed on the sale (net tax rate method); null without it.
     */
    public function marginAfterVatRp(): ?int
    {
        $margin = $this->marginRp();

        return $margin === null || $this->netTaxRp === null ? null : $margin - $this->netTaxRp;
    }

    /**
     * Margin as a percentage of revenue, one decimal.
     */
    public function marginPercent(): ?float
    {
        $margin = $this->marginRp();

        if ($margin === null || $this->revenueRp === 0) {
            return null;
        }

        return round($margin / $this->revenueRp * 100, 1);
    }
}
