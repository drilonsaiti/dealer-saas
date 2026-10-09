<?php

namespace App\Domain\Financing\Actions;

use App\Domain\Checklists\Actions\SyncChecklist;
use App\Domain\Checklists\Models\ChecklistItem;
use App\Domain\Financing\Enums\BuybackStatus;
use App\Domain\Financing\Enums\FinancingStatus;
use App\Domain\Financing\Models\BuybackObligation;
use App\Domain\Financing\Models\Financing;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Support\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moves a financing along: approved / rejected → contract received (revocation period
 * starts, partner checklist, buy-back obligation) → signed → documents sent (only with a
 * complete partner checklist; payout due 10 days later). "Paid out" follows the payment.
 */
class ChangeFinancingStatus
{
    public const REVOCATION_DAYS = 14;

    public const PAYOUT_DAYS = 10;

    public const BUYBACK_REMINDER_MONTHS = 3;

    public function __construct(private readonly SyncChecklist $checklists) {}

    /**
     * @param  array<string, mixed>  $data  contract_received: received_on, contract_number, term_months, residual_rp, monthly_rate_rp, has_buyback
     */
    public function __invoke(Financing $financing, FinancingStatus $to, array $data = []): Financing
    {
        if (! in_array($to, $financing->status->next(), true)) {
            throw new BusinessRuleException(__('A financing cannot go from ":from" to ":to".', ['from' => $financing->status->getLabel(), 'to' => $to->getLabel()]));
        }

        return DB::transaction(function () use ($financing, $to, $data): Financing {
            match ($to) {
                FinancingStatus::ContractReceived => $this->contractReceived($financing, $data),
                FinancingStatus::DocumentsSent => $this->documentsSent($financing),
                FinancingStatus::Rejected, FinancingStatus::Cancelled => $this->ended($financing),
                default => null,
            };

            $financing->forceFill(['status' => $to])->save();

            return $financing->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function contractReceived(Financing $financing, array $data): void
    {
        $received = Carbon::parse($data['received_on'] ?? Carbon::today());
        $financing->fill(array_intersect_key($data, array_flip(['contract_number', 'term_months', 'residual_rp', 'monthly_rate_rp', 'has_buyback', 'km_per_year', 'nominal_rate'])));

        if (blank($financing->contract_number)) {
            throw new BusinessRuleException(__('Enter the contract number of the bank.'));
        }

        $financing->forceFill([
            'contract_received_on' => $received->toDateString(),
            'revocation_until' => $received->copy()->addDays(self::REVOCATION_DAYS)->toDateString(),
        ])->save();

        $this->checklists->partner($financing);

        if ($financing->has_buyback && (int) $financing->residual_rp > 0) {
            if ($financing->term_months === null) {
                throw new BusinessRuleException(__('Enter the term in months: the buy-back is due at the end of the lease.'));
            }

            $due = $received->copy()->addMonthsNoOverflow($financing->term_months);
            BuybackObligation::query()->updateOrCreate(['financing_id' => $financing->getKey()], [
                'vehicle_id' => $financing->sale->stockCycle->vehicle_id,
                'amount_rp' => (int) $financing->residual_rp,
                'due_on' => $due->toDateString(),
                'remind_on' => $due->copy()->subMonthsNoOverflow(self::BUYBACK_REMINDER_MONTHS)->toDateString(),
            ]);
        }
    }

    private function documentsSent(Financing $financing): void
    {
        $open = $this->checklists->partner($financing)->openRequired();

        if ($open->isNotEmpty()) {
            throw BusinessRuleException::because([
                __('The bank still needs:'),
                $open->map(fn (ChecklistItem $item): string => $item->label)->implode(', ').'.',
            ]);
        }

        $financing->forceFill([
            'documents_sent_on' => Carbon::today()->toDateString(),
            'payout_due_on' => Carbon::today()->addDays(self::PAYOUT_DAYS)->toDateString(),
        ])->save();
    }

    /**
     * Rejected or cancelled: the customer is invoiced again (if nothing went to the bank yet),
     * an open buy-back obligation lapses.
     */
    private function ended(Financing $financing): void
    {
        $sale = $financing->sale;
        $bankInvoiced = Invoice::query()->where('sale_id', $sale->getKey())->where('recipient_party_id', $financing->partner_party_id)
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Cancelled->value])->exists();

        if ($bankInvoiced) {
            throw new BusinessRuleException(__('The bank has an open invoice. Credit it first.'));
        }

        $sale->forceFill(['invoice_recipient_party_id' => null])->save();
        $financing->buyback?->forceFill(['status' => BuybackStatus::Released, 'settled_on' => Carbon::today()->toDateString()])->save();
    }
}
