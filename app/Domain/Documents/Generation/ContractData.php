<?php

namespace App\Domain\Documents\Generation;

use App\Domain\Documents\Enums\TemplateType;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Parties\Enums\PartyKind;
use App\Domain\Parties\Enums\Salutation;
use App\Domain\Parties\Models\Party;
use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Settings\Support\Iban;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Domain\Vehicles\Support\Stammnummer;
use DateTimeInterface;

/**
 * Everything a contract shows, collected once from the vehicle file, the parties and the
 * dealer's settings. The result is stored with the document (data_snapshot) and the PDF is
 * rendered from it alone, so later changes to the car or the customer never change a
 * finalised contract, and every PDF can be reproduced and explained.
 *
 * Labels (fuel, payment type...) are resolved in the document's language here.
 */
class ContractData
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<string, mixed>
     */
    public function forSale(Sale $sale, DocumentTemplate $template, string $locale, string $number, ?string $remarks): array
    {
        return $this->inLocale($locale, function () use ($sale, $template, $locale, $number, $remarks): array {
            $sale->loadMissing(['stockCycle.vehicle', 'buyer', 'holder', 'invoiceRecipient', 'items', 'tradeIn']);
            $cycle = $sale->stockCycle;
            $tradeIn = $sale->tradeIn;

            return [
                ...$this->common(TemplateType::SalesContract, $template, $locale, $number, $remarks),
                'buyer' => $this->party($sale->buyer),
                'holder' => $sale->holder_party_id !== null && $sale->holder_party_id !== $sale->buyer_party_id ? $this->party($sale->holder) : null,
                'invoice_recipient' => $sale->invoice_recipient_party_id !== null && $sale->invoice_recipient_party_id !== $sale->buyer_party_id ? $this->party($sale->invoiceRecipient) : null,
                'vehicle' => $this->vehicle($cycle->vehicle, $sale->mileage_at_handover ?? $cycle->mileage_out ?? $cycle->mileage_in),
                'sale' => [
                    'sale_on' => $this->date($sale->sale_on),
                    'planned_handover_on' => $this->date($sale->planned_handover_on),
                    'price_rp' => $sale->price_rp,
                    'discount_rp' => $sale->discount_rp,
                    'items' => $sale->items->map(fn (SaleItem $item): array => [
                        'kind' => $item->kind->getLabel(),
                        'description' => $item->description,
                        'qty' => (float) $item->qty,
                        'unit_price_rp' => $item->unit_price_rp,
                        'total_rp' => $item->totalRp(),
                    ])->values()->all(),
                    'total_rp' => $sale->totalRp(),
                    'deposit_rp' => $sale->deposit_rp,
                    'balance_rp' => $sale->balanceRp(),
                    'payment_type' => $sale->payment_type->value,
                    'payment_type_label' => $sale->payment_type->getLabel(),
                ],
                'trade_in' => $tradeIn === null ? null : [
                    'vehicle' => $tradeIn->vehicleName(),
                    'stammnummer' => Stammnummer::format($tradeIn->vehicle_data['stammnummer'] ?? null),
                    'vin' => $tradeIn->vehicle_data['vin'] ?? null,
                    'first_registration_on' => $tradeIn->vehicle_data['first_registration_on'] ?? null,
                    'mileage' => $tradeIn->mileage,
                    'value_rp' => $tradeIn->value_rp,
                    'payoff_rp' => $tradeIn->payoff_rp,
                    'customer_payout_rp' => $tradeIn->customer_payout_rp,
                    'customer_topup_rp' => $tradeIn->customer_topup_rp,
                    'credited_rp' => $tradeIn->credited_rp,
                ],
                // Promises to the customer are printed automatically, nothing is written by hand.
                'commitments' => $this->commitments($cycle),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function forPurchase(Purchase $purchase, DocumentTemplate $template, string $locale, string $number, ?string $remarks): array
    {
        return $this->inLocale($locale, function () use ($purchase, $template, $locale, $number, $remarks): array {
            $purchase->loadMissing(['stockCycle.vehicle', 'seller', 'payoffParty']);

            return [
                ...$this->common(TemplateType::PurchaseContract, $template, $locale, $number, $remarks),
                'seller' => $purchase->seller === null ? null : $this->party($purchase->seller),
                'vehicle' => $this->vehicle($purchase->stockCycle->vehicle, $purchase->mileage ?? $purchase->stockCycle->mileage_in),
                'purchase' => [
                    'contract_on' => $this->date($purchase->contract_on),
                    'delivered_on' => $this->date($purchase->delivered_on),
                    'seller_kind' => $purchase->seller_kind->getLabel(),
                    'purchase_type' => $purchase->purchase_type->getLabel(),
                    'price_rp' => $purchase->price_rp,
                    'vat_shown_rp' => $purchase->vat_shown_rp,
                    'payoff_rp' => $purchase->payoff_rp,
                    'payoff_party' => $purchase->payoffParty?->displayName(),
                    'to_seller_rp' => $purchase->price_rp - (int) $purchase->payoff_rp,
                    'known_defects' => $purchase->known_defects,
                    'agreed_deliverables' => $purchase->agreed_deliverables,
                ],
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function common(TemplateType $type, DocumentTemplate $template, string $locale, string $number, ?string $remarks): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->context->tenant();
        $bank = BankAccount::query()->orderByDesc('is_default')->orderBy('created_at')->first();

        return [
            'type' => $type->value,
            'locale' => $locale,
            'number' => $number,
            'date' => now()->toDateString(),
            'company' => [
                'name' => $tenant->legal_name ?: $tenant->name,
                'street' => $tenant->street,
                'place' => trim(implode(' ', array_filter([$tenant->zip, $tenant->city]))),
                'phone' => $tenant->phone,
                'email' => $tenant->email,
                'website' => $tenant->website,
                'uid' => $tenant->uid,
                'vat_number' => $tenant->vat_number,
                'logo_path' => $tenant->logo_path,
                'brand_color' => $tenant->brand_color,
            ],
            'bank' => $bank === null ? null : [
                'bank_name' => $bank->bank_name,
                'holder' => $bank->account_holder ?: ($tenant->legal_name ?: $tenant->name),
                'iban' => Iban::format($bank->iban),
                'bic' => $bank->bic,
            ],
            'remarks' => filled($remarks) ? trim((string) $remarks) : null,
            'clauses' => $template->clausesIn($locale),
            'footer' => $template->footerIn($locale),
            'template' => ['id' => $template->getKey(), 'version' => $template->version],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function party(Party $party): array
    {
        return [
            'name' => $party->kind === PartyKind::Company && filled($party->company_name)
                ? (string) $party->company_name
                : trim(implode(' ', array_filter([$party->salutation === Salutation::Company ? null : $party->salutation?->getLabel(), $party->first_name, $party->last_name]))),
            'contact' => $party->kind === PartyKind::Company ? trim(implode(' ', array_filter([$party->first_name, $party->last_name]))) : null,
            'street' => $party->street,
            'place' => trim(implode(' ', array_filter([$party->zip, $party->city]))),
            'country' => $party->country !== 'CH' ? $party->country : null,
            'phone' => $party->mobile ?: $party->phone,
            'email' => $party->email,
            'uid' => $party->uid,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vehicle(Vehicle $vehicle, ?int $mileage): array
    {
        return [
            'name' => $vehicle->displayName(),
            'body_type' => $vehicle->body_type?->getLabel(),
            'fuel' => $vehicle->fuel?->getLabel(),
            'transmission' => $vehicle->transmission?->getLabel(),
            'mileage' => $mileage,
            'vin' => $vehicle->vin,
            'stammnummer' => $vehicle->formattedStammnummer(),
            'type_approval' => $vehicle->type_approval,
            'displacement_cc' => $vehicle->displacement_cc,
            'power_kw' => $vehicle->power_kw,
            'power_ps' => $vehicle->power_kw === null ? null : (int) round($vehicle->power_kw * 1.35962),
            'curb_weight_kg' => $vehicle->curb_weight_kg,
            'total_weight_kg' => $vehicle->total_weight_kg,
            'color_exterior' => $vehicle->color_exterior,
            'first_registration_on' => $this->date($vehicle->first_registration_on),
            'mfk_last_on' => $this->date($vehicle->mfk_last_on),
            'plate' => $vehicle->plate,
        ];
    }

    /**
     * @return list<array{description: string, due_on: string|null, done: bool}>
     */
    private function commitments(StockCycle $cycle): array
    {
        return Commitment::query()
            ->where('stock_cycle_id', $cycle->getKey())
            ->orderBy('created_at')
            ->get()
            ->map(fn (Commitment $commitment): array => [
                'description' => $commitment->description,
                'due_on' => $this->date($commitment->due_on),
                'done' => $commitment->isDone(),
            ])
            ->values()
            ->all();
    }

    private function date(?DateTimeInterface $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inLocale(string $locale, callable $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
