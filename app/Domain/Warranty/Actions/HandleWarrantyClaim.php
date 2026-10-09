<?php

namespace App\Domain\Warranty\Actions;

use App\Domain\Purchasing\Enums\CostStatus;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Warranty\Enums\ClaimStatus;
use App\Domain\Warranty\Enums\WarrantyStatus;
use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Models\WarrantyClaim;
use App\Support\BusinessRuleException;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A warranty case: report (date, km, description, workshop) → decide (amount, deductible
 * paid by the customer, provider share, dealer share) → settle. The dealer's share is booked
 * as a cost on the original vehicle file, even after archiving, so the real margin is right.
 */
class HandleWarrantyClaim
{
    /**
     * @param  array<string, mixed>  $data  occurred_on, mileage, description, diagnosis, workshop_party_id
     */
    public function report(Warranty $warranty, array $data): WarrantyClaim
    {
        if ($warranty->status !== WarrantyStatus::Active && $warranty->status !== WarrantyStatus::Expired) {
            throw new BusinessRuleException(__('The warranty starts at handover; a claim needs an active warranty.'));
        }

        if (blank($data['description'] ?? null) || blank($data['occurred_on'] ?? null) || ! isset($data['mileage'])) {
            throw new BusinessRuleException(__('Enter date, mileage and what happened.'));
        }

        return WarrantyClaim::create([...$data, 'warranty_id' => $warranty->getKey()]);
    }

    /**
     * Reasons the claim is outside the cover (shown as a warning, the provider decides).
     *
     * @return list<string>
     */
    public function outsideCover(WarrantyClaim $claim): array
    {
        $warranty = $claim->warranty;
        $problems = [];

        if ($warranty->ends_on !== null && $claim->occurred_on->greaterThan($warranty->ends_on)) {
            $problems[] = __('After the end of the warranty (:date).', ['date' => $warranty->ends_on->format('d.m.Y')]);
        }

        if ($warranty->starts_on !== null && $claim->occurred_on->lessThan($warranty->starts_on)) {
            $problems[] = __('Before the start of the warranty (:date).', ['date' => $warranty->starts_on->format('d.m.Y')]);
        }

        if ($warranty->kmUntil() !== null && $claim->mileage > $warranty->kmUntil()) {
            $problems[] = __('Above the km limit (:km km).', ['km' => number_format((int) $warranty->kmUntil(), 0, '.', '’')]);
        }

        if ($warranty->coverage_limit_rp !== null && $claim->amount_rp > $warranty->coverage_limit_rp) {
            $problems[] = __('Above the coverage limit (:amount).', ['amount' => Money::format($warranty->coverage_limit_rp)]);
        }

        return $problems;
    }

    /**
     * @param  array{amount_rp: int, deductible_rp: int, provider_share_rp: int, dealer_share_rp: int}|null  $shares  null = rejected (dealer pays nothing)
     */
    public function decide(WarrantyClaim $claim, ?array $shares, ?string $diagnosis = null): WarrantyClaim
    {
        if (! in_array($claim->status, [ClaimStatus::Open, ClaimStatus::Approved], true)) {
            throw new BusinessRuleException(__('This claim is already settled.'));
        }

        if ($shares === null) {
            $claim->forceFill(['status' => ClaimStatus::Rejected, 'diagnosis' => $diagnosis ?? $claim->diagnosis, 'closed_on' => Carbon::today()->toDateString()])->save();

            return $claim;
        }

        if ($shares['amount_rp'] !== $shares['deductible_rp'] + $shares['provider_share_rp'] + $shares['dealer_share_rp']) {
            throw new BusinessRuleException(__('Deductible, provider share and dealer share must add up to the amount (:amount).', ['amount' => Money::format($shares['amount_rp'])]));
        }

        $claim->forceFill([...$shares, 'status' => ClaimStatus::Approved, 'diagnosis' => $diagnosis ?? $claim->diagnosis])->save();

        return $claim;
    }

    public function settle(WarrantyClaim $claim, ?string $on = null): WarrantyClaim
    {
        if ($claim->status !== ClaimStatus::Approved) {
            throw new BusinessRuleException(__('Decide the claim first.'));
        }

        return DB::transaction(function () use ($claim, $on): WarrantyClaim {
            $date = Carbon::parse($on ?? Carbon::today());
            $cost = null;

            if ($claim->dealer_share_rp > 0) {
                $cost = new Cost([
                    'stock_cycle_id' => $claim->warranty->stock_cycle_id,
                    'category_id' => CostCategory::query()->where('key', 'repair')->value('id') ?? CostCategory::query()->orderBy('sort')->value('id'),
                    'incurred_on' => $date->toDateString(),
                    'description' => __('Warranty claim of :date: :text', ['date' => $claim->occurred_on->format('d.m.Y'), 'text' => str($claim->description)->limit(80)->toString()]),
                    'supplier_party_id' => $claim->workshop_party_id,
                    'gross_rp' => $claim->dealer_share_rp,
                ]);
                $cost->forceFill(['status' => CostStatus::Confirmed])->save();
            }

            $claim->forceFill(['status' => ClaimStatus::Closed, 'closed_on' => $date->toDateString(), 'cost_id' => $cost?->getKey()])->save();

            return $claim;
        });
    }
}
