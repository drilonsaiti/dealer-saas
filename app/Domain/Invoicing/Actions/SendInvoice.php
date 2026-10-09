<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Mail\InvoiceMail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Tenancy\TenantContext;
use App\Support\BusinessRuleException;
use App\Support\Money;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the issued PDF by email, only when a user clicks (spec: no automatic sending).
 */
class SendInvoice
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Invoice $invoice, string $email): void
    {
        $version = $invoice->document?->currentVersion;

        if (! $invoice->status->isIssued() || $version === null) {
            throw new BusinessRuleException(__('Only an issued invoice can be sent.'));
        }

        $tenant = $this->context->tenant();
        $open = $invoice->type === InvoiceType::CreditNote ? 0 : $invoice->openRp();

        Mail::to($email)->locale($invoice->locale)->send(new InvoiceMail(
            $invoice->type->labelIn($invoice->locale).' '.$invoice->number,
            $invoice->recipient->displayName(),
            (string) ($tenant?->legal_name ?: $tenant?->name),
            $open > 0 ? Money::format($open) : null,
            $invoice->due_on?->format('d.m.Y'),
            $version->contents(),
            $version->original_name,
        ));

        $invoice->forceFill(['sent_at' => now()])->save();
    }
}
