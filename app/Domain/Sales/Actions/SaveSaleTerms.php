<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\TradeIn;
use App\Support\BusinessRuleException;
use Illuminate\Support\Arr;

/**
 * Writes price, parties, extra items and the (not yet confirmed) trade-in of a sale.
 * Shared by reserving and contracting; never touches the status.
 */
class SaveSaleTerms
{
    /**
     * @param  array<string, mixed>  $data  sale attributes + optional 'items' list + optional 'trade_in' array
     */
    public function __invoke(Sale $sale, array $data): Sale
    {
        if (blank($data['buyer_party_id'] ?? $sale->buyer_party_id)) {
            throw new BusinessRuleException(__('Choose the buyer.'));
        }

        if (! is_int($data['price_rp'] ?? $sale->getAttribute('price_rp'))) {
            throw new BusinessRuleException(__('Enter the sale price.'));
        }

        $sale->fill(Arr::except($data, ['items', 'trade_in', 'has_trade_in']))->save();

        if (array_key_exists('items', $data)) {
            $this->syncItems($sale, (array) $data['items']);
        }

        if (array_key_exists('trade_in', $data) || array_key_exists('has_trade_in', $data)) {
            $this->syncTradeIn($sale, ($data['has_trade_in'] ?? true) ? ($data['trade_in'] ?? null) : null);
        }

        return $sale;
    }

    /**
     * @param  array<int|string, mixed>  $items
     */
    private function syncItems(Sale $sale, array $items): void
    {
        $sale->items()->delete();

        foreach (array_values($items) as $i => $item) {
            if (! is_array($item) || blank($item['description'] ?? null)) {
                continue;
            }

            $sale->items()->create([
                'kind' => $item['kind'] ?? 'other',
                'description' => $item['description'],
                'qty' => $item['qty'] ?? 1,
                'unit_price_rp' => (int) ($item['unit_price_rp'] ?? 0),
                'sort' => $i,
            ]);
        }

        $sale->unsetRelation('items');
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function syncTradeIn(Sale $sale, ?array $data): void
    {
        $existing = $sale->tradeIn()->first();

        if ($existing?->isConfirmed()) {
            // The trade-in car already has its own file; change it there.
            return;
        }

        if ($data === null || ! isset($data['value_rp'])) {
            $existing?->delete();
            $sale->unsetRelation('tradeIn');

            return;
        }

        $tradeIn = $existing ?? new TradeIn(['sale_id' => $sale->getKey()]);
        $tradeIn->fill([
            'vehicle_data' => Arr::only((array) ($data['vehicle'] ?? []), ['stammnummer', 'vin', 'make', 'model', 'variant', 'first_registration_on', 'color_exterior', 'plate']),
            'mileage' => $data['mileage'] ?? null,
            'value_rp' => (int) $data['value_rp'],
            'payoff_rp' => (int) ($data['payoff_rp'] ?? 0),
            'payoff_party_id' => $data['payoff_party_id'] ?? null,
            'customer_payout_rp' => (int) ($data['customer_payout_rp'] ?? 0),
            'customer_topup_rp' => (int) ($data['customer_topup_rp'] ?? 0),
            'settlement_basis' => $data['settlement_basis'] ?? null,
            'condition_notes' => $data['condition_notes'] ?? null,
        ])->save();

        $sale->unsetRelation('tradeIn');
    }
}
