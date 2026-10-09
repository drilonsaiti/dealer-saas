<?php

namespace App\Domain\Vat\Rules;

use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLine;
use App\Domain\Vat\Enums\TaxEventState;
use App\Domain\Vat\Enums\VatCodeKind;
use App\Domain\Vat\Enums\VatMethod;
use App\Domain\Vat\Models\VatNetTaxRate;
use App\Domain\Vat\Models\VatProfile;
use App\Domain\Vat\Support\VatMath;
use App\Support\Money;

/**
 * Turns one invoice line (or its share of a payment, received basis) into a tax event.
 * Decides the ESTV field, the tax owed under the dealer's method and the control state.
 *
 * Net tax rate method: the approved rate is owed on the gross taxable amount (VAT included);
 * input tax is never deducted. Credit notes on taxable sales reduce the consideration (235).
 * Changing this logic means a new VERSION; existing events keep the version they were made with.
 */
class InvoiceLineRule
{
    public const KEY = 'invoice_line';

    public const VERSION = '2026.1';

    /**
     * @param  int  $amountRp  gross amount of the line (signed), or its share of a payment
     * @return array<string, mixed>|null TaxEvent attributes without source, date and period; null = not tax relevant
     */
    public function apply(InvoiceLine $line, Invoice $invoice, int $amountRp, VatProfile $profile): ?array
    {
        if (! $profile->liable || $amountRp === 0) {
            return null;
        }

        $code = $line->vatCode;
        $isCredit = $invoice->type === InvoiceType::CreditNote;
        $steps = [self::step(':invoice, line :position: :amount', ['invoice' => (string) $invoice->number, 'position' => $line->position, 'amount' => Money::format($amountRp)])];
        $event = [
            'invoice_id' => $invoice->getKey(),
            'vat_code_id' => $code?->getKey(),
            'kind' => $code !== null ? $code->kind : VatCodeKind::Taxable,
            'field' => '200',
            'base_rp' => $amountRp,
            'legal_rate' => 0,
            'tax_rp' => 0,
            'net_tax_rate_id' => null,
            'net_rate' => null,
            'rule_key' => self::KEY,
            'rule_version' => self::VERSION,
        ];

        if ($code === null) {
            return $this->blocked($event, $steps, 'The line has no VAT code.');
        }

        if ($profile->method === VatMethod::Effective) {
            return $this->blocked($event, $steps, 'The effective method is not released yet; the return cannot be prepared automatically.');
        }

        return match ($code->kind) {
            VatCodeKind::Taxable, VatCodeKind::NoTaxShown => $this->taxable($event, $steps, $line, $amountRp, $profile, $code->kind, $isCredit),
            VatCodeKind::ExportExempt => [...$event, 'field' => '220', 'state' => TaxEventState::Confirm, 'explanation' => self::explain([...$steps,
                self::step('Export: exempt from VAT (field 220) only with proof of export, e.g. the customs clearance. Confirm once the proof is in the vehicle file.'),
            ], $line)],
            VatCodeKind::Excluded => [...$event, 'field' => '230', 'state' => TaxEventState::Auto, 'explanation' => self::explain([...$steps,
                self::step('Excluded from VAT (art. 21 MWSTG): reported in field 230, no tax.'),
            ], $line)],
            VatCodeKind::ReverseCharge, VatCodeKind::Import => $this->blocked($event, $steps, 'This VAT case (reverse charge / import) is not supported yet.'),
        };
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  list<array{text: string, params: array<string, mixed>}>  $steps
     * @return array<string, mixed>
     */
    private function taxable(array $event, array $steps, InvoiceLine $line, int $amountRp, VatProfile $profile, VatCodeKind $kind, bool $isCredit): array
    {
        $rates = $profile->netTaxRates;
        $rate = $rates->first();

        if (! $rate instanceof VatNetTaxRate) {
            return $this->blocked($event, $steps, 'No approved net tax rate is set up (Settings → VAT).');
        }

        $legalRate = $kind === VatCodeKind::Taxable ? (float) $line->vat_rate : 0.0;
        // The VAT printed on the line (rounded per rate on the invoice), or its share of a payment.
        $legalVat = $legalRate === 0.0 ? 0 : ($amountRp === $line->total_rp ? $line->vat_rp : VatMath::includedVat($amountRp, $legalRate));
        $state = TaxEventState::Auto;

        $steps[] = $kind === VatCodeKind::Taxable
            ? self::step('Taxable domestic supply, :rate % VAT included (:vat).', ['rate' => self::percent($legalRate), 'vat' => Money::format($legalVat)])
            : self::step('No VAT is shown on the invoice, but a VAT-registered dealer still owes tax on this sale: it counts as taxable turnover.');

        if ($kind === VatCodeKind::NoTaxShown) {
            $state = TaxEventState::Confirm;
        }

        if ($rates->count() > 1) {
            $state = TaxEventState::Confirm;
            $steps[] = self::step('Two net tax rates are approved: check that the activity ":activity" is right.', ['activity' => $rate->activity]);
        }

        $tax = (int) round($amountRp * (float) $rate->rate / 100);
        $steps[] = self::step('Net tax rate method: :rate % (:activity) of the gross amount = :tax.', ['rate' => self::percent((float) $rate->rate), 'activity' => $rate->activity, 'tax' => Money::format($tax)]);

        if ($isCredit) {
            $steps[] = self::step('Credit note: reduces the consideration (field 235) in the period of the credit note.');
        }

        return [
            ...$event,
            'field' => $isCredit ? '235' : '200',
            'legal_rate' => $legalRate,
            'tax_rp' => $tax,
            'net_tax_rate_id' => $rate->getKey(),
            'net_rate' => $rate->rate,
            'state' => $state,
            'explanation' => self::explain($steps, $line, $legalVat),
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  list<array{text: string, params: array<string, mixed>}>  $steps
     * @return array<string, mixed>
     */
    private function blocked(array $event, array $steps, string $reason): array
    {
        return [...$event, 'state' => TaxEventState::Blocked, 'explanation' => ['steps' => [...$steps, self::step($reason)], 'missing' => $reason]];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{text: string, params: array<string, mixed>}
     */
    public static function step(string $text, array $params = []): array
    {
        return ['text' => $text, 'params' => $params];
    }

    /**
     * @param  list<array{text: string, params: array<string, mixed>}>  $steps
     * @return array<string, mixed>
     */
    private static function explain(array $steps, InvoiceLine $line, int $legalVatRp = 0): array
    {
        return ['steps' => $steps, 'line_id' => $line->getKey(), 'legal_vat_rp' => $legalVatRp];
    }

    public static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
