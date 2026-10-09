<?php

namespace App\Domain\Invoicing\Support;

use App\Domain\Documents\Generation\DocumentRenderer;
use App\Domain\Documents\Support\PdfRenderer;
use App\Domain\Invoicing\QrBill\QrBill;
use App\Domain\Invoicing\QrBill\QrBillRenderer;
use App\Support\BusinessRuleException;
use Throwable;

/**
 * Invoice PDF from its snapshot: letter, lines, VAT, and the Swiss QR bill on the last page.
 */
class InvoiceRenderer
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly QrBillRenderer $qr,
    ) {}

    /**
     * @param  array<string, mixed>  $d
     */
    public function html(array $d): string
    {
        $previous = app()->getLocale();
        app()->setLocale((string) $d['locale']);

        try {
            $bill = self::qrBill($d);

            return view('documents.invoices.invoice', [
                'd' => $d,
                'logo' => DocumentRenderer::logoDataUri($d['company']['logo_path'] ?? null),
                'qrHtml' => $bill === null ? null : $this->qr->html($bill, (string) $d['locale']),
            ])->render();
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * @param  array<string, mixed>  $d
     */
    public function pdf(array $d): string
    {
        if (! $this->pdf->isAvailable()) {
            throw new BusinessRuleException(__('PDFs cannot be created: the PDF service (Gotenberg) is not configured.'));
        }

        try {
            return $this->pdf->fromHtml($this->html($d), cssPages: true);
        } catch (BusinessRuleException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new BusinessRuleException(__('The PDF could not be created. Is the PDF service (Gotenberg) running?'));
        }
    }

    /**
     * @param  array<string, mixed>  $d
     */
    public static function qrBill(array $d): ?QrBill
    {
        $qr = $d['qr'] ?? null;

        if (! is_array($qr)) {
            return null;
        }

        $company = $d['company'];
        $recipient = $d['recipient'];

        return new QrBill(
            (string) $qr['iban'],
            ['name' => (string) $company['name'], 'street' => $company['street'], 'zip' => (string) $company['zip'], 'city' => (string) $company['city'], 'country' => $company['country'] ?? 'CH'],
            $qr['amount_rp'],
            filled($recipient['name'] ?? null) && filled($recipient['zip'] ?? null) && filled($recipient['city'] ?? null)
                ? ['name' => (string) $recipient['name'], 'street' => $recipient['street'] ?? null, 'zip' => (string) $recipient['zip'], 'city' => (string) $recipient['city'], 'country' => $recipient['country'] ?? 'CH']
                : null,
            (string) $qr['reference_type'],
            $qr['reference'],
            (string) __(':type :number', ['type' => $d['title'], 'number' => $d['number']]),
        );
    }
}
