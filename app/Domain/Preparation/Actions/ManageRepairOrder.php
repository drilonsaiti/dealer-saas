<?php

namespace App\Domain\Preparation\Actions;

use App\Domain\Preparation\Enums\RepairOrderStatus;
use App\Domain\Preparation\Models\Damage;
use App\Domain\Preparation\Models\RepairOrder;
use App\Domain\Purchasing\Enums\CostStatus;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Repair orders: create (optionally from damages) → approve (approved amount counts in the
 * margin as an estimate) → done (actual cost booked as a confirmed cost) or cancel.
 */
class ManageRepairOrder
{
    /**
     * @param  array<string, mixed>  $data  workshop_party_id, description, estimate_rp, target_on, blocks_release
     * @param  list<string>  $damageIds
     */
    public function create(StockCycle $cycle, array $data, array $damageIds = []): RepairOrder
    {
        if ($cycle->isLocked()) {
            throw new BusinessRuleException(__('This vehicle file is closed and cannot be changed.'));
        }

        if (blank($data['description'] ?? null)) {
            throw new BusinessRuleException(__('Describe the work.'));
        }

        return DB::transaction(function () use ($cycle, $data, $damageIds): RepairOrder {
            $order = RepairOrder::create([...$data, 'stock_cycle_id' => $cycle->getKey()]);
            Damage::query()->where('stock_cycle_id', $cycle->getKey())->whereIn('id', $damageIds)->update(['repair_order_id' => $order->getKey()]);

            return $order;
        });
    }

    public function approve(RepairOrder $order, int $approvedRp): RepairOrder
    {
        if ($order->status !== RepairOrderStatus::Estimate) {
            throw new BusinessRuleException(__('Only an estimate can be approved.'));
        }

        if ($approvedRp <= 0) {
            throw new BusinessRuleException(__('Enter the approved amount.'));
        }

        $order->forceFill(['status' => RepairOrderStatus::Approved, 'approved_rp' => $approvedRp, 'approved_at' => now(), 'approved_by' => Auth::id()])->save();

        return $order;
    }

    public function done(RepairOrder $order, int $actualRp, ?string $on = null, ?int $vatRp = null): RepairOrder
    {
        if (! $order->status->isOpen()) {
            throw new BusinessRuleException(__('This repair order is already closed.'));
        }

        return DB::transaction(function () use ($order, $actualRp, $on, $vatRp): RepairOrder {
            $date = Carbon::parse($on ?? Carbon::today());
            $cost = null;

            if ($actualRp > 0) {
                $cost = new Cost([
                    'stock_cycle_id' => $order->stock_cycle_id,
                    'category_id' => CostCategory::query()->where('key', 'repair')->value('id') ?? CostCategory::query()->orderBy('sort')->value('id'),
                    'incurred_on' => $date->toDateString(),
                    'description' => str($order->description)->limit(200)->toString(),
                    'supplier_party_id' => $order->workshop_party_id,
                    'gross_rp' => $actualRp,
                    'vat_rp' => $vatRp,
                ]);
                $cost->forceFill(['status' => CostStatus::Confirmed])->save();
            }

            $order->forceFill(['status' => RepairOrderStatus::Done, 'done_on' => $date->toDateString(), 'cost_id' => $cost?->getKey()])->save();

            return $order;
        });
    }

    public function cancel(RepairOrder $order): RepairOrder
    {
        if (! $order->status->isOpen()) {
            throw new BusinessRuleException(__('This repair order is already closed.'));
        }

        $order->forceFill(['status' => RepairOrderStatus::Cancelled])->save();
        $order->damages()->update(['repair_order_id' => null]);

        return $order;
    }
}
