<?php

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLine;
use App\Domain\Parties\Enums\PartyKind;
use App\Domain\Parties\Models\Party;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Settings\Support\Iban;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;

/**
 * Everything an issued invoice shows, frozen with the document (like contracts): the PDF is
 * rendered from this alone, so the invoice can always be reproduced exactly.
 */
class InvoiceData
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array{iban: string, reference_type: string, reference: string|null, amount_rp: int|null}|null  $qr
     * @return array<string, mixed>
     */
    public function for(Invoice $invoice, ?BankAccount $bank, ?array $qr): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->context->tenant();
        $invoice->loadMissing(['lines.vatCode', 'recipient', 'stockCycle.vehicle', 'credits']);
        $locale = $invoice->locale;

        $vatByRate = $invoice->lines
            ->filter(fn (InvoiceLine $line): bool => (float) $line->vat_rate > 0)
            ->groupBy(fn (InvoiceLine $line): string => rtrim(rtrim(number_format((float) $line->vat_rate, 2, '.', ''), '0'), '.'))
            ->map(fn ($lines, string $rate): array => ['rate' => $rate, 'base_rp' => (int) $lines->sum('total_rp'), 'vat_rp' => (int) $lines->sum('vat_rp')])
            ->values()
            ->all();

        return [
            'locale' => $locale,
            'type' => $invoice->type->value,
            'title' => $invoice->type->labelIn($locale),
            'number' => $invoice->number,
            'issued_on' => $invoice->issued_on?->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            'service_on' => $invoice->service_on?->toDateString(),
            'credits' => $invoice->credits === null ? null : ['number' => $invoice->credits->number, 'issued_on' => $invoice->credits->issued_on?->toDateString()],
            'company' => [
                'name' => $tenant->legal_name ?: $tenant->name,
                'street' => $tenant->street,
                'zip' => $tenant->zip,
                'city' => $tenant->city,
                'country' => $tenant->country ?: 'CH',
                'phone' => $tenant->phone,
                'email' => $tenant->email,
                'website' => $tenant->website,
                'uid' => $tenant->uid,
                'vat_number' => $tenant->vat_number,
                'logo_path' => $tenant->logo_path,
                'brand_color' => $tenant->brand_color,
            ],
            'recipient' => $invoice->recipient_snapshot ?? self::party($invoice->recipient),
            'vehicle' => $invoice->stockCycle === null ? null : [
                'file' => $invoice->stockCycle->number,
                'name' => $invoice->stockCycle->vehicle->displayName(),
                'stammnummer' => $invoice->stockCycle->vehicle->formattedStammnummer(),
            ],
            'lines' => $invoice->lines->map(fn (InvoiceLine $line): array => [
                'description' => $line->description,
                'qty' => (float) $line->qty,
                'unit_price_rp' => $line->unit_price_rp,
                'total_rp' => $line->total_rp,
                'vat_rate' => (float) $line->vat_rate,
                'vat_label' => $line->vatCode?->getTranslation('label', $locale),
            ])->values()->all(),
            'net_rp' => $invoice->net_rp,
            'vat_rp' => $invoice->vat_rp,
            'total_rp' => $invoice->total_rp,
            'vat_by_rate' => $vatByRate,
            'payments_rp' => $invoice->paid_rp,
            'open_rp' => $invoice->type === InvoiceType::CreditNote ? null : $invoice->openRp(),
            'notes' => $invoice->notes,
            'bank' => $bank === null ? null : [
                'bank_name' => $bank->bank_name,
                'holder' => $bank->account_holder ?: ($tenant->legal_name ?: $tenant->name),
                'iban' => Iban::format($bank->iban),
                'bic' => $bank->bic,
            ],
            'qr' => $qr,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public static function party(Party $party): array
    {
        $person = trim(implode(' ', array_filter([$party->first_name, $party->last_name])));

        return [
            'name' => $party->kind === PartyKind::Company && filled($party->company_name) ? (string) $party->company_name : $person,
            'contact' => $party->kind === PartyKind::Company && $person !== '' ? $person : null,
            'street' => $party->street,
            'zip' => $party->zip,
            'city' => $party->city,
            'country' => $party->country ?: 'CH',
            'uid' => $party->uid,
        ];
    }
}
