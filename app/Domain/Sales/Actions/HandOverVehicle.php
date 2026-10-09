<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Checklists\Actions\SyncChecklist;
use App\Domain\Checklists\Actions\TickChecklistItem;
use App\Domain\Checklists\Models\ChecklistItem;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Warranty\Actions\ActivateWarranties;
use App\Support\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The customer drives away: mileage and date recorded, file "delivered", sale "delivered",
 * warranties start. Every required item of the handover checklist must be done (promises,
 * payment or leasing payout, warranty registered, keys…); manual items can be confirmed in
 * the same step.
 */
class HandOverVehicle
{
    public function __construct(
        private readonly TransitionStockCycle $transition,
        private readonly SyncChecklist $checklists,
        private readonly TickChecklistItem $tick,
        private readonly ActivateWarranties $warranties,
    ) {}

    /**
     * @param  list<string>  $confirm  ids of checklist items the user confirms now
     */
    public function __invoke(Sale $sale, int $mileage, ?string $on = null, array $confirm = []): Sale
    {
        if (! in_array($sale->status, [SaleStatus::Contracted, SaleStatus::Invoiced], true)) {
            throw new BusinessRuleException(__('Only a contracted sale can be handed over.'));
        }

        $date = $on === null ? Carbon::today() : Carbon::parse($on);

        return DB::transaction(function () use ($sale, $mileage, $date, $confirm): Sale {
            $checklist = $this->checklists->handover($sale);

            foreach ($checklist->items as $item) {
                if (in_array($item->getKey(), $confirm, true) && ! $item->isDone() && $item->applicable) {
                    ($this->tick)($item);
                }
            }

            $open = $checklist->refresh()->load('items')->openRequired();

            if ($open->isNotEmpty()) {
                throw BusinessRuleException::because([
                    __('Not ready for handover:'),
                    $open->map(fn (ChecklistItem $item): string => $item->label)->implode(', ').'.',
                ]);
            }

            ($this->transition)($sale->stockCycle, StockCycleStatus::Delivered, data: ['mileage_out' => $mileage, 'on' => $date]);

            $sale->forceFill([
                'status' => SaleStatus::Delivered,
                'delivered_on' => $date,
                'mileage_at_handover' => $mileage,
            ])->save();

            ($this->warranties)($sale, $date, $mileage);

            return $sale;
        });
    }

    /**
     * Warnings that do not block (shown in the handover dialog).
     *
     * @return list<string>
     */
    public function warnings(Sale $sale): array
    {
        $financing = $sale->financing;

        if ($financing !== null && $financing->revocation_until === null) {
            return [__('The leasing contract is not in yet.')];
        }

        if ($financing !== null && $financing->inRevocationPeriod()) {
            return [__('The customer can still revoke the leasing until :date: a handover before that is at the dealer’s risk.', ['date' => $financing->revocation_until->format('d.m.Y')])];
        }

        return [];
    }
}
