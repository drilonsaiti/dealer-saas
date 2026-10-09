<?php

namespace App\Domain\Vat\Actions;

use App\Domain\Vat\Enums\TaxEventState;
use App\Domain\Vat\Enums\VatPeriodStatus;
use App\Domain\Vat\Models\TaxEvent;
use App\Domain\Vat\Models\VatNetTaxRate;
use App\Domain\Vat\Rules\InvoiceLineRule;
use App\Support\BusinessRuleException;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;

/**
 * A user confirms a "please confirm" entry (optionally choosing the other approved net tax
 * rate); from then on it counts in the period. The confirmation is logged on the entry.
 */
class ConfirmTaxEvent
{
    public function __invoke(TaxEvent $event, ?string $netTaxRateId = null): TaxEvent
    {
        if ($event->state !== TaxEventState::Confirm || $event->confirmed_at !== null) {
            throw new BusinessRuleException(__('Only entries waiting for confirmation can be confirmed.'));
        }

        if ($event->period !== null && $event->period->status !== VatPeriodStatus::Open) {
            throw new BusinessRuleException(__('The VAT period is closed.'));
        }

        $explanation = $event->explanation;

        if ($netTaxRateId !== null && $netTaxRateId !== $event->net_tax_rate_id) {
            $rate = VatNetTaxRate::query()->findOrFail($netTaxRateId);
            $tax = (int) round($event->base_rp * (float) $rate->rate / 100);
            $event->forceFill(['net_tax_rate_id' => $rate->getKey(), 'net_rate' => $rate->rate, 'tax_rp' => $tax]);
            $explanation['steps'][] = InvoiceLineRule::step('Corrected to :rate % (:activity) = :tax.', ['rate' => InvoiceLineRule::percent((float) $rate->rate), 'activity' => $rate->activity, 'tax' => Money::format($tax)]);
        }

        $explanation['steps'][] = InvoiceLineRule::step('Confirmed by :name on :date.', ['name' => (string) Auth::user()?->name, 'date' => now()->format('d.m.Y H:i')]);

        $event->forceFill(['confirmed_by' => Auth::id(), 'confirmed_at' => now(), 'explanation' => $explanation])->save();

        return $event;
    }
}
