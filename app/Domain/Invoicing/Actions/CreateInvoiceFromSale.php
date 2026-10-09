<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\InvoiceLineKind;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Vat\Models\VatCode;
use App\Support\BusinessRuleException;

/**
 * Invoice from the sale with one click (draft, checked before issuing): the deposit invoice
 * for the agreed deposit, or the final invoice with vehicle, items and minus the deposits
 * already invoiced. In a leasing deal the invoice goes to the financing partner.
 */
class CreateInvoiceFromSale
{
    public function __construct(
        private readonly SaveInvoiceDraft $save,
    ) {}

    public function __invoke(Sale $sale, InvoiceType $type): Invoice
    {
        if (! in_array($type, [InvoiceType::Deposit, InvoiceType::Final], true)) {
            throw new BusinessRuleException(__('From a sale you can create a deposit or a final invoice.'));
        }

        if (! in_array($sale->status, [SaleStatus::Reserved, SaleStatus::Contracted, SaleStatus::Invoiced, SaleStatus::Delivered], true)) {
            throw new BusinessRuleException(__('This sale cannot be invoiced.'));
        }

        $existing = Invoice::query()->where('sale_id', $sale->getKey())->where('type', $type->value)->where('status', '!=', InvoiceStatus::Cancelled->value)->first();

        if ($existing !== null) {
            throw new BusinessRuleException($existing->status === InvoiceStatus::Draft
                ? __('There is already a draft of this invoice.')
                : __('This sale already has a :type (:number).', ['type' => $type->getLabel(), 'number' => $existing->number]));
        }

        $sale->loadMissing(['stockCycle.vehicle', 'items', 'buyer']);
        $locale = $sale->locale;
        $code = $this->defaultVatCode();
        $vehicle = $sale->stockCycle->vehicle;
        $vehicleText = trim($vehicle->displayName().', '.collect([
            $vehicle->formattedStammnummer() ? __('Stammnummer', locale: $locale).' '.$vehicle->formattedStammnummer() : null,
            $vehicle->vin ? __('VIN', locale: $locale).' '.$vehicle->vin : null,
            $vehicle->first_registration_on ? __('First registration', locale: $locale).' '.$vehicle->first_registration_on->format('d.m.Y') : null,
        ])->filter()->implode(', '), ', ');

        $lines = match ($type) {
            InvoiceType::Deposit => $this->depositLines($sale, $vehicleText, $code, $locale),
            default => $this->finalLines($sale, $vehicleText, $code, $locale),
        };

        return ($this->save)(null, [
            'type' => $type,
            'sale_id' => $sale->getKey(),
            'stock_cycle_id' => $sale->stock_cycle_id,
            'recipient_party_id' => $sale->invoice_recipient_party_id ?? $sale->buyer_party_id,
            'locale' => $locale,
            'service_on' => ($sale->planned_handover_on ?? $sale->sale_on)?->toDateString(),
        ], $lines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function depositLines(Sale $sale, string $vehicleText, VatCode $code, string $locale): array
    {
        if ($sale->deposit_rp <= 0) {
            throw new BusinessRuleException(__('The sale has no deposit.'));
        }

        return [[
            'kind' => InvoiceLineKind::Deposit->value,
            'description' => __('Deposit for :vehicle', ['vehicle' => $vehicleText], $locale),
            'unit_price_rp' => $sale->deposit_rp,
            'vat_code_id' => $code->getKey(),
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function finalLines(Sale $sale, string $vehicleText, VatCode $code, string $locale): array
    {
        $lines = [[
            'kind' => InvoiceLineKind::Vehicle->value,
            'description' => $vehicleText,
            'unit_price_rp' => $sale->price_rp,
            'vat_code_id' => $code->getKey(),
        ]];

        if ($sale->discount_rp > 0) {
            $lines[] = ['kind' => InvoiceLineKind::Item->value, 'description' => __('Discount', locale: $locale), 'unit_price_rp' => -$sale->discount_rp, 'vat_code_id' => $code->getKey()];
        }

        foreach ($sale->items as $item) {
            /** @var SaleItem $item */
            $lines[] = ['kind' => InvoiceLineKind::Item->value, 'description' => $item->description, 'qty' => (float) $item->qty, 'unit_price_rp' => $item->unit_price_rp, 'vat_code_id' => $code->getKey()];
        }

        $deposits = Invoice::query()->where('sale_id', $sale->getKey())->where('type', InvoiceType::Deposit->value)
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Paid->value])
            ->with('lines')->orderBy('issued_on')->get();

        foreach ($deposits as $deposit) {
            foreach ($deposit->lines as $line) {
                $lines[] = [
                    'kind' => InvoiceLineKind::DepositDeduction->value,
                    'description' => __('Less deposit invoice :number of :date', ['number' => $deposit->number, 'date' => $deposit->issued_on?->format('d.m.Y')], $locale),
                    'qty' => (float) $line->qty,
                    'unit_price_rp' => -$line->unit_price_rp,
                    'vat_code_id' => $line->vat_code_id,
                    'source_invoice_id' => $deposit->getKey(),
                ];
            }
        }

        return $lines;
    }

    private function defaultVatCode(): VatCode
    {
        return VatCode::defaultForSales();
    }
}
