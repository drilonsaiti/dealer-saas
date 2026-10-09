<?php

namespace App\Domain\Vehicles\Actions;

use App\Domain\Audit\Models\StatusHistory;
use App\Domain\Documents\Models\Document;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Settings\Actions\IssueNumber;
use App\Domain\Settings\Enums\NumberSequenceKey;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Vehicles\Enums\Code178Status;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Events\StockCycleStatusChanged;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only way a stock cycle changes status: checks that the step is allowed, that its
 * guard holds and that back steps have a reason, applies the side effects (number,
 * milestone dates), writes the status history and fires StockCycleStatusChanged.
 */
class TransitionStockCycle
{
    public const DEFAULT_ARCHIVE_AFTER_DAYS = 30;

    public function __construct(
        private readonly IssueNumber $issueNumber,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{mileage_out?: int|string|null, on?: string|Carbon|null, reason?: string|null}  $data
     */
    public function __invoke(StockCycle $cycle, StockCycleStatus $to, ?string $reason = null, array $data = []): StockCycle
    {
        $reason = blank($reason) ? null : trim((string) $reason);

        $from = DB::transaction(function () use ($cycle, $to, $reason, $data): StockCycleStatus {
            /** @var StockCycle $locked */
            $locked = StockCycle::query()->with('vehicle')->lockForUpdate()->findOrFail($cycle->getKey());
            $from = $locked->status;

            $problems = $this->problems($locked, $to, $reason, $data);

            if ($problems !== []) {
                throw BusinessRuleException::because($problems);
            }

            $this->applySideEffects($locked, $to, $data);

            $locked->forceFill(['status' => $to])->save();

            StatusHistory::record($locked, $from->value, $to->value, $reason);

            $cycle->setRawAttributes($locked->getAttributes(), sync: true);

            return $from;
        });

        StockCycleStatusChanged::dispatch($cycle, $from, $to, $reason);

        return $cycle;
    }

    /**
     * Why this transition is not possible right now (empty list = allowed).
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function problems(StockCycle $cycle, StockCycleStatus $to, ?string $reason = null, array $data = []): array
    {
        $from = $cycle->status;

        if (! $from->canTransitionTo($to)) {
            return [__('A vehicle file cannot go from ":from" to ":to".', ['from' => $from->getLabel(), 'to' => $to->getLabel()])];
        }

        $problems = [];

        if (($from->isBackStepTo($to) || $to === StockCycleStatus::Cancelled) && blank($reason)) {
            $problems[] = __('Please give a reason for this status change.');
        }

        if ($from->isBackStepTo($to) && in_array($from, [StockCycleStatus::Reserved, StockCycleStatus::Sold], true) && $this->activeSale($cycle) !== null) {
            $problems[] = __('Cancel the reservation or sale first.');
        }

        $problems = [...$problems, ...match ($to) {
            StockCycleStatus::Purchased => $this->purchaseProblems($cycle),
            StockCycleStatus::Reserved => [...$this->code178Problems($cycle), ...($this->activeSale($cycle)?->status === SaleStatus::Reserved ? [] : [__('Reserve the vehicle for a customer first.')])],
            StockCycleStatus::Sold => $this->activeSale($cycle)?->status === SaleStatus::Contracted ? [] : [__('Record the sale contract first.')],
            StockCycleStatus::Listed => [...$this->code178Problems($cycle), ...$this->listingProblems($cycle)],
            StockCycleStatus::Delivered => $this->deliveryProblems($cycle, $data),
            StockCycleStatus::Archived => $this->archiveProblems($cycle),
            default => [],
        }];

        return $problems;
    }

    /**
     * A file becomes "purchased" only with a recorded purchase: seller, price and date.
     *
     * @return list<string>
     */
    private function purchaseProblems(StockCycle $cycle): array
    {
        $purchase = $cycle->purchase()->first();

        if ($purchase === null) {
            return [__('Record the purchase (seller, price and date) first.')];
        }

        return $purchase->seller_party_id === null ? [__('Choose the seller.')] : [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function deliveryProblems(StockCycle $cycle, array $data): array
    {
        if (! in_array($this->activeSale($cycle)?->status, [SaleStatus::Contracted, SaleStatus::Invoiced], true)) {
            return [__('Record the sale contract first.')];
        }

        $mileageOut = $data['mileage_out'] ?? null;

        if ($mileageOut === null || $mileageOut === '') {
            return [__('Enter the mileage at handover.')];
        }

        if ($cycle->mileage_in !== null && (int) $mileageOut < $cycle->mileage_in) {
            return [__('The mileage at handover cannot be lower than the mileage at purchase.')];
        }

        $open = $cycle->commitments()->open()->where('blocks_handover', true)->count();

        if ($open > 0) {
            return [__('Promises to the customer still open: :count. Mark them as done first.', ['count' => $open])];
        }

        return [];
    }

    /**
     * A car with the leasing bank's code 178 still in the registration cannot be resold.
     *
     * @return list<string>
     */
    private function code178Problems(StockCycle $cycle): array
    {
        return $cycle->vehicle->code178_status === Code178Status::Entered
            ? [__('Code 178 is still entered in the registration document. Have the bank clear it first.')]
            : [];
    }

    /**
     * @return list<string>
     */
    private function archiveProblems(StockCycle $cycle): array
    {
        $days = $this->archiveAfterDays();

        if ($cycle->delivered_on === null || $cycle->delivered_on->copy()->addDays($days)->isFuture()) {
            return [__('A vehicle file can be archived :days days after delivery.', ['days' => $days])];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applySideEffects(StockCycle $cycle, StockCycleStatus $to, array $data): void
    {
        $on = isset($data['on']) && filled($data['on']) ? Carbon::parse($data['on']) : Carbon::today();

        match ($to) {
            StockCycleStatus::Purchased => $this->markPurchased($cycle),
            StockCycleStatus::ReadyForSale => $cycle->ready_on ??= $on,
            StockCycleStatus::Listed => $cycle->listed_on = $on,
            StockCycleStatus::Sold => $cycle->sold_on = $on,
            StockCycleStatus::Delivered => $cycle->forceFill(['delivered_on' => $on, 'mileage_out' => (int) $data['mileage_out']]),
            StockCycleStatus::Archived => $cycle->archived_on = $on,
            default => null,
        };

        if ($cycle->status === StockCycleStatus::Listed && $to !== StockCycleStatus::Reserved && $to !== StockCycleStatus::Sold) {
            $cycle->listed_on = null;
        }

        if ($cycle->status === StockCycleStatus::Sold && $to === StockCycleStatus::ReadyForSale) {
            $cycle->sold_on = null;
        }
    }

    private function markPurchased(StockCycle $cycle): void
    {
        $purchase = $cycle->purchase()->firstOrFail();

        $cycle->purchased_on = $purchase->contract_on;
        // The file belongs to its purchase year, even when the car is sold the next year.
        $cycle->file_year = (int) $purchase->contract_on->format('Y');

        if ($cycle->number === null) {
            $cycle->number = ($this->issueNumber)(NumberSequenceKey::StockCycle);
        }
    }

    /**
     * Listing needs a price and at least one photo in the file.
     *
     * @return list<string>
     */
    private function listingProblems(StockCycle $cycle): array
    {
        $problems = [];

        if ($cycle->list_price_rp === null) {
            $problems[] = __('Set a list price before listing the vehicle.');
        }

        $hasPhoto = Document::query()
            ->linkedTo($cycle)
            ->whereHas('category', fn ($query) => $query->where('key', 'photo'))
            ->exists();

        if (! $hasPhoto) {
            $problems[] = __('Add at least one photo before listing the vehicle.');
        }

        return $problems;
    }

    private function activeSale(StockCycle $cycle): ?Sale
    {
        return Sale::query()->active()->where('stock_cycle_id', $cycle->getKey())->first();
    }

    private function archiveAfterDays(): int
    {
        $tenant = $this->context->tenant();

        return max(0, (int) ($tenant?->setting('vehicles.archive_after_days', self::DEFAULT_ARCHIVE_AFTER_DAYS) ?? self::DEFAULT_ARCHIVE_AFTER_DAYS));
    }
}
