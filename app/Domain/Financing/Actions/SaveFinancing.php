<?php

namespace App\Domain\Financing\Actions;

use App\Domain\Financing\Enums\FinancingKind;
use App\Domain\Financing\Enums\FinancingStatus;
use App\Domain\Financing\Models\Financing;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Parties\Models\Party;
use App\Domain\Sales\Enums\PaymentType;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Support\BusinessRuleException;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records (or corrects) the leasing or credit of a sale. The bank becomes the invoice
 * recipient, the customer stays buyer and holder; expected payout = cash price − first
 * instalment collected by the dealer (Cembra BMW X3: 26'900 − 4'500 = 22'400).
 */
class SaveFinancing
{
    /**
     * @param  array<string, mixed>  $data  partner_party_id, kind, applied_on, cash_price_rp, collection_rp, term_months, km_per_year, residual_rp, nominal_rate, monthly_rate_rp, has_buyback, contract_number, notes
     */
    public function __invoke(Sale $sale, array $data, ?Financing $financing = null): Financing
    {
        $financing ??= $sale->financing;
        $this->guard($sale, $data, $financing);

        return DB::transaction(function () use ($sale, $data, $financing): Financing {
            $financing ??= new Financing(['sale_id' => $sale->getKey()]);
            $financing->fill([
                'applied_on' => Carbon::today()->toDateString(),
                ...$financing->exists ? [] : ['cash_price_rp' => $sale->loadMissing('items')->totalRp()],
                ...$data,
            ]);
            $financing->forceFill(['payout_expected_rp' => $financing->cash_price_rp - $financing->collection_rp])->save();

            $partner = Party::query()->findOrFail($financing->partner_party_id);
            $partner->addRole(PartyRole::FinancingPartner);

            if ($partner->isDirty('roles')) {
                $partner->save();
            }

            $sale->forceFill([
                'payment_type' => $financing->kind === FinancingKind::Credit ? PaymentType::Credit : PaymentType::Leasing,
                'invoice_recipient_party_id' => $partner->getKey(),
                'holder_party_id' => $sale->holder_party_id ?? $sale->buyer_party_id,
            ])->save();

            return $financing->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function guard(Sale $sale, array $data, ?Financing $financing): void
    {
        $problems = [];

        if (in_array($sale->status, [SaleStatus::Delivered, SaleStatus::Cancelled], true)) {
            $problems[] = __('The sale is already delivered or cancelled.');
        }

        if ($financing !== null && in_array($financing->status, [FinancingStatus::DocumentsSent, FinancingStatus::PaidOut], true)) {
            $problems[] = __('The documents are with the bank: the financing can no longer be changed.');
        }

        if (blank($data['partner_party_id'] ?? $financing?->partner_party_id)) {
            $problems[] = __('Choose the leasing bank.');
        }

        $cash = (int) ($data['cash_price_rp'] ?? ($financing !== null ? $financing->cash_price_rp : $sale->loadMissing('items')->totalRp()));
        $collection = (int) ($data['collection_rp'] ?? ($financing !== null ? $financing->collection_rp : 0));

        if ($cash <= 0) {
            $problems[] = __('Enter the cash price.');
        }

        if ($collection < 0 || $collection >= $cash) {
            $problems[] = __('The first instalment collected must be less than the cash price (:amount).', ['amount' => Money::format($cash)]);
        }

        $partnerInvoice = Invoice::query()->where('sale_id', $sale->getKey())->where('status', '!=', InvoiceStatus::Draft->value)
            ->where('recipient_party_id', '!=', $data['partner_party_id'] ?? $financing?->partner_party_id)->exists();

        if ($financing === null && $partnerInvoice) {
            $problems[] = __('An invoice to the customer is already issued. Credit it first, then the bank can be invoiced.');
        }

        if ($problems !== []) {
            throw BusinessRuleException::because($problems);
        }
    }
}
