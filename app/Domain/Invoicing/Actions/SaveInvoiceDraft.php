<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\InvoiceLineKind;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\LineAmounts;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Vat\Models\VatCode;
use App\Domain\Vat\Support\VatMath;
use App\Support\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates or changes a draft invoice with its lines. Amounts are recalculated here and again
 * when the invoice is issued (with the rate valid on the invoice date).
 */
class SaveInvoiceDraft
{
    public const DEFAULT_PAYMENT_DAYS = 10;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $data  type, recipient_party_id, sale_id, stock_cycle_id, locale, service_on, due_on, notes, credits_invoice_id
     * @param  list<array{kind?: string, description: string, qty?: float|int|string, unit_price_rp: int, vat_code_id?: string|null, source_invoice_id?: string|null}>  $lines
     */
    public function __invoke(?Invoice $invoice, array $data, array $lines): Invoice
    {
        if ($invoice !== null && $invoice->status->isIssued()) {
            throw new BusinessRuleException(__('An issued invoice cannot be changed. Issue a credit note instead.'));
        }

        $lines = array_values(array_filter($lines, fn (array $line): bool => filled($line['description'])));

        if ($lines === []) {
            throw new BusinessRuleException(__('The invoice needs at least one line.'));
        }

        return DB::transaction(function () use ($invoice, $data, $lines): Invoice {
            $invoice ??= new Invoice;
            $invoice->fill([
                ...$data,
                'locale' => $data['locale'] ?? $invoice->locale ?? ($this->context->tenant()->default_locale ?? 'de'),
                'due_on' => $data['due_on'] ?? $invoice->due_on ?? now()->addDays($this->paymentDays())->toDateString(),
            ]);
            $invoice->save();

            $invoice->lines()->delete();

            foreach ($lines as $i => $line) {
                $invoice->lines()->create([
                    'position' => $i + 1,
                    'kind' => $line['kind'] ?? InvoiceLineKind::Item->value,
                    'description' => mb_substr((string) $line['description'], 0, 500),
                    'qty' => (float) ($line['qty'] ?? 1),
                    'unit_price_rp' => (int) $line['unit_price_rp'],
                    'vat_code_id' => $line['vat_code_id'] ?? null,
                    'source_invoice_id' => $line['source_invoice_id'] ?? null,
                    'vat_rate' => 0,
                    'net_rp' => 0,
                    'vat_rp' => 0,
                    'total_rp' => 0,
                ]);
            }

            return self::recalculate($invoice->refresh(), Carbon::today());
        });
    }

    /**
     * Line amounts and totals with the VAT rates valid on $on.
     */
    public static function recalculate(Invoice $invoice, Carbon $on): Invoice
    {
        $codes = VatCode::query()->whereIn('id', $invoice->lines()->pluck('vat_code_id')->filter())->get()->keyBy('id');
        $lines = $invoice->lines()->get();
        $amounts = [];

        foreach ($lines as $line) {
            $amounts[$line->getKey()] = LineAmounts::for($codes->get((string) $line->vat_code_id), $line->unit_price_rp, (float) $line->qty, $on);
        }

        // The VAT of each rate is computed on the total of that rate (as shown on the invoice);
        // the last line of the rate takes the rounding difference so the lines add up.
        foreach (collect($amounts)->groupBy(fn (array $a): string => (string) $a['vat_rate'], true) as $rate => $group) {
            $expected = VatMath::includedVat((int) $group->sum('total_rp'), (float) $rate);
            $difference = $expected - (int) $group->sum('vat_rp');

            if ($difference !== 0) {
                $last = $group->keys()->last();
                $amounts[$last]['vat_rp'] += $difference;
                $amounts[$last]['net_rp'] -= $difference;
            }
        }

        $net = $vat = $total = 0;

        foreach ($lines as $line) {
            $line->forceFill($amounts[$line->getKey()])->save();
            $net += $amounts[$line->getKey()]['net_rp'];
            $vat += $amounts[$line->getKey()]['vat_rp'];
            $total += $amounts[$line->getKey()]['total_rp'];
        }

        if ($invoice->type !== InvoiceType::CreditNote && $total < 0) {
            throw new BusinessRuleException(__('The invoice total cannot be negative. Use a credit note.'));
        }

        $invoice->forceFill(['net_rp' => $net, 'vat_rp' => $vat, 'total_rp' => $total])->save();
        $invoice->unsetRelation('lines');

        return $invoice;
    }

    private function paymentDays(): int
    {
        return (int) ($this->context->tenant()?->setting('invoicing.payment_days', self::DEFAULT_PAYMENT_DAYS) ?? self::DEFAULT_PAYMENT_DAYS);
    }
}
