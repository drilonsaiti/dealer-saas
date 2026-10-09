<?php

namespace App\Domain\Warranty\Actions;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Sales\Enums\SaleItemKind;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Warranty\Enums\WarrantyStatus;
use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Models\WarrantyProduct;
use App\Support\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sells a warranty with the car: the price becomes a sale item (on the contract and the
 * invoice), the premium a cost of the vehicle file (in the margin), the policy a draft that
 * becomes active at handover. Remove takes all three back while nothing is invoiced.
 */
class AddWarranty
{
    /**
     * @param  array<string, mixed>  $overrides  price_rp, cost_rp, coverage_limit_rp, km_limit, duration_months, deductible_rp
     */
    public function __invoke(Sale $sale, WarrantyProduct $product, array $overrides = []): Warranty
    {
        $this->guard($sale);

        return DB::transaction(function () use ($sale, $product, $overrides): Warranty {
            $price = (int) ($overrides['price_rp'] ?? $product->price_rp);
            $costRp = (int) ($overrides['cost_rp'] ?? $product->cost_rp);
            $name = $product->getTranslation('name', $sale->locale);

            $item = SaleItem::create([
                'sale_id' => $sale->getKey(),
                'kind' => SaleItemKind::Warranty,
                'description' => $name,
                'qty' => 1,
                'unit_price_rp' => $price,
                'sort' => (int) $sale->items()->max('sort') + 1,
            ]);

            $cost = $costRp <= 0 ? null : Cost::create([
                'stock_cycle_id' => $sale->stock_cycle_id,
                'category_id' => CostCategory::query()->where('key', 'warranty')->value('id') ?? CostCategory::query()->orderBy('sort')->value('id'),
                'incurred_on' => Carbon::today()->toDateString(),
                'description' => __('Warranty premium :name', ['name' => $name]),
                'supplier_party_id' => $product->provider_party_id,
                'gross_rp' => $costRp,
                'is_estimate' => true,
            ]);

            $warranty = Warranty::create([
                'stock_cycle_id' => $sale->stock_cycle_id,
                'sale_id' => $sale->getKey(),
                'product_id' => $product->getKey(),
                'duration_months' => (int) ($overrides['duration_months'] ?? $product->duration_months),
                'km_limit' => $overrides['km_limit'] ?? $product->km_limit,
                'coverage_limit_rp' => $overrides['coverage_limit_rp'] ?? $product->coverage_limit_rp,
                'deductible_rp' => (int) ($overrides['deductible_rp'] ?? $product->deductible_rp),
                'cost_rp' => $costRp,
                'price_rp' => $price,
            ]);
            $warranty->forceFill(['sale_item_id' => $item->getKey(), 'cost_id' => $cost?->getKey()])->save();

            return $warranty;
        });
    }

    public function remove(Warranty $warranty): void
    {
        if ($warranty->status !== WarrantyStatus::Draft) {
            throw new BusinessRuleException(__('Only a warranty that is not active yet can be removed.'));
        }

        if ($warranty->sale !== null) {
            $this->guard($warranty->sale);
        }

        DB::transaction(function () use ($warranty): void {
            $item = $warranty->sale_item_id;
            $cost = $warranty->cost;
            $warranty->forceFill(['status' => WarrantyStatus::Cancelled, 'sale_item_id' => null, 'cost_id' => null])->save();
            SaleItem::query()->whereKey($item)->delete();

            if ($cost !== null && ! $cost->isConfirmed()) {
                $cost->delete();
            }
        });
    }

    private function guard(Sale $sale): void
    {
        $invoiced = Invoice::query()->where('sale_id', $sale->getKey())
            ->whereIn('type', [InvoiceType::Final->value, InvoiceType::Standard->value])
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])->exists();

        if ($invoiced) {
            throw new BusinessRuleException(__('The final invoice is already issued. Credit it first.'));
        }

        if (! in_array($sale->status, [SaleStatus::Reserved, SaleStatus::Contracted], true)) {
            throw new BusinessRuleException(__('A warranty can be added to a reserved or contracted sale.'));
        }
    }
}
