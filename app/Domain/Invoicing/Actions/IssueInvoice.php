<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Documents\Actions\InstallDefaultDocumentCategories;
use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\QrBill\QrBill;
use App\Domain\Invoicing\QrBill\QrReference;
use App\Domain\Invoicing\Support\InvoiceData;
use App\Domain\Invoicing\Support\InvoiceRenderer;
use App\Domain\Payments\Actions\RecordPayment;
use App\Domain\Payments\Enums\PaymentDirection;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Support\Balances;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Settings\Actions\IssueNumber;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Settings\Models\NumberSequence;
use App\Domain\Settings\Support\Iban;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Vat\Actions\RecordTaxEvents;
use App\Support\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Issues a draft: the number (gap-free, only now), amounts with today's VAT rates, the QR
 * reference, the recipient as of today, and the locked PDF in the vehicle file. From now on
 * the invoice never changes. A final invoice also books the trade-in as payment and marks
 * the sale as invoiced. The tax events are recorded with it (agreed basis).
 */
class IssueInvoice
{
    public function __construct(
        private readonly IssueNumber $issueNumber,
        private readonly InvoiceData $data,
        private readonly InvoiceRenderer $renderer,
        private readonly StoreDocument $store,
        private readonly RecordPayment $payments,
        private readonly RecordTaxEvents $taxEvents,
    ) {}

    public function __invoke(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new BusinessRuleException(__('This invoice is already issued.'));
        }

        $invoice->loadMissing(['recipient', 'sale.tradeIn', 'stockCycle']);
        $this->guardRecipient($invoice);

        return DB::transaction(function () use ($invoice): Invoice {
            $today = Carbon::today();
            $number = ($this->issueNumber)($invoice->type->numberSequence());
            $bank = $invoice->type === InvoiceType::CreditNote ? null : $this->bankAccount($invoice);

            $invoice->forceFill([
                'number' => $number,
                'issued_on' => $today->toDateString(),
                'recipient_snapshot' => InvoiceData::party($invoice->recipient),
                'bank_account_id' => $bank?->getKey(),
                'status' => InvoiceStatus::Issued,
            ])->save();

            SaveInvoiceDraft::recalculate($invoice, $today);

            if ($invoice->type === InvoiceType::CreditNote) {
                $this->applyCreditNote($invoice);
            } else {
                [$type, $reference] = $this->reference($bank, $number);
                $invoice->forceFill([
                    'qr_iban' => $bank !== null ? Iban::normalize($bank->qr_iban ?: $bank->iban) : null,
                    'reference_type' => $type,
                    'qr_reference' => $reference,
                ])->save();

                $this->bookTradeIn($invoice);
                $this->markSaleInvoiced($invoice);
            }

            $invoice->refresh();
            $qr = $this->qrData($invoice);
            $snapshot = $this->data->for($invoice, $bank, $qr);

            // Validates the QR bill before anything is stored.
            try {
                InvoiceRenderer::qrBill($snapshot);
            } catch (InvalidArgumentException $e) {
                throw new BusinessRuleException(__('The QR bill cannot be created: :reason', ['reason' => $e->getMessage()]));
            }

            $this->file($invoice, $snapshot);
            $this->taxEvents->invoiceIssued($invoice);

            return $invoice->refresh();
        });
    }

    private function guardRecipient(Invoice $invoice): void
    {
        $recipient = $invoice->recipient;
        $tenant = app(TenantContext::class)->tenant();

        if ($tenant === null || blank($tenant->zip) || blank($tenant->city)) {
            throw new BusinessRuleException(__('Enter your company’s postcode and town first (Settings → Company); they are printed on the QR bill.'));
        }

        if (blank($recipient->zip) || blank($recipient->city)) {
            throw new BusinessRuleException(__(':name needs a postcode and town for the invoice.', ['name' => $recipient->displayName()]));
        }

        if ($invoice->lines()->doesntExist()) {
            throw new BusinessRuleException(__('The invoice needs at least one line.'));
        }

        if ($invoice->type !== InvoiceType::CreditNote && NumberSequence::query()->where('key', $invoice->type->numberSequence()->value)->doesntExist()) {
            throw new BusinessRuleException(__('Set up the invoice number range first (Settings → Numbering).'));
        }
    }

    private function bankAccount(Invoice $invoice): BankAccount
    {
        $bank = $invoice->bankAccount
            ?? BankAccount::query()->orderByDesc('is_default')->orderByRaw('qr_iban is null')->orderBy('created_at')->first();

        if ($bank === null) {
            throw new BusinessRuleException(__('Add a bank account first (Settings → Bank accounts); it is printed on the QR bill.'));
        }

        return $bank;
    }

    /**
     * QR reference with a QR-IBAN, otherwise a creditor reference (RF..). Both are built from
     * the invoice number, which is unique per dealer.
     *
     * @return array{0: string, 1: string}
     */
    private function reference(?BankAccount $bank, string $number): array
    {
        if ($bank !== null && filled($bank->qr_iban)) {
            return [QrBill::TYPE_QRR, QrReference::qrr((string) preg_replace('/\D/', '', $number))];
        }

        return [QrBill::TYPE_SCOR, QrReference::scor($number)];
    }

    /**
     * @return array{iban: string, reference_type: string, reference: string|null, amount_rp: int|null}|null
     */
    private function qrData(Invoice $invoice): ?array
    {
        if ($invoice->type === InvoiceType::CreditNote || $invoice->qr_iban === null || $invoice->openRp() <= 0) {
            return null;
        }

        return [
            'iban' => $invoice->qr_iban,
            'reference_type' => (string) $invoice->reference_type,
            'reference' => $invoice->qr_reference,
            'amount_rp' => $invoice->openRp(),
        ];
    }

    /**
     * The trade-in car pays part of the final invoice.
     */
    private function bookTradeIn(Invoice $invoice): void
    {
        $tradeIn = $invoice->sale?->tradeIn;

        if ($invoice->type !== InvoiceType::Final || $tradeIn === null || $tradeIn->credited_rp <= 0) {
            return;
        }

        $amount = min($tradeIn->credited_rp, $invoice->openRp());

        if ($amount > 0) {
            ($this->payments)([
                'direction' => PaymentDirection::In,
                'paid_on' => $invoice->issued_on?->toDateString(),
                'amount_rp' => $amount,
                'method' => PaymentMethod::TradeInOffset,
                'party_id' => $invoice->sale->buyer_party_id,
                'notes' => __('Trade-in :vehicle', ['vehicle' => $tradeIn->vehicleName()]),
            ], [[$invoice->refresh(), $amount]]);
        }
    }

    private function markSaleInvoiced(Invoice $invoice): void
    {
        $sale = $invoice->sale;

        if ($sale !== null && in_array($invoice->type, [InvoiceType::Final, InvoiceType::Standard], true) && $sale->status === SaleStatus::Contracted) {
            $sale->forceFill(['status' => SaleStatus::Invoiced])->save();
        }
    }

    private function applyCreditNote(Invoice $creditNote): void
    {
        $original = $creditNote->credits;

        if ($original === null) {
            throw new BusinessRuleException(__('A credit note must refer to an invoice.'));
        }

        Balances::invoice($original->refresh());

        // A fully credited sale invoice gives the sale back (it can then be cancelled or invoiced anew).
        $sale = $original->sale;

        if ($original->refresh()->status === InvoiceStatus::Cancelled && $sale !== null && $sale->status === SaleStatus::Invoiced
            && ! Invoice::query()->where('sale_id', $sale->getKey())->whereIn('type', [InvoiceType::Final->value, InvoiceType::Standard->value])
                ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Paid->value])->exists()) {
            $sale->forceFill(['status' => SaleStatus::Contracted])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function file(Invoice $invoice, array $snapshot): void
    {
        $pdf = $this->renderer->pdf($snapshot);
        $file = tempnam(sys_get_temp_dir(), 'invoice');
        file_put_contents($file, $pdf);
        $categoryKey = $invoice->type === InvoiceType::CreditNote ? 'credit_note' : 'invoice';
        $category = DocumentCategory::query()->where('key', $categoryKey)->first();

        if ($category === null) {
            app(InstallDefaultDocumentCategories::class)();
            $category = DocumentCategory::query()->where('key', $categoryKey)->firstOrFail();
        }

        try {
            $document = ($this->store)($file, $category, [
                'title' => $invoice->type->labelIn($invoice->locale).' '.$invoice->number,
                'document_on' => $invoice->issued_on?->toDateString(),
                'locale' => $invoice->locale,
                'source' => DocumentSource::Generated,
                'original_name' => str_replace(' ', '-', $invoice->type->labelIn($invoice->locale)).'_'.$invoice->number.'_'.strtoupper($invoice->locale).'.pdf',
            ], array_values(array_filter([$invoice->stockCycle, $invoice->sale, $invoice->recipient], fn (?Model $m): bool => $m !== null)));
        } finally {
            @unlink($file);
        }

        $document->currentVersion?->forceFill(['data_snapshot' => $snapshot, 'locked_at' => now()])->save();
        $document->forceFill(['type_key' => $categoryKey, 'number' => $invoice->number])->save();
        $invoice->forceFill(['document_id' => $document->getKey()])->save();
    }
}
