<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\RequiredDocumentStatus;
use App\Domain\Documents\Models\RequiredDocument;
use App\Domain\Vehicles\Models\StockCycle;

/**
 * "Requested from the seller" or "not required for this car". Present/missing are computed.
 */
class SetRequiredDocumentStatus
{
    public function __invoke(StockCycle $cycle, string $categoryKey, RequiredDocumentStatus $status, ?string $note = null): void
    {
        if (in_array($status, [RequiredDocumentStatus::Missing, RequiredDocumentStatus::Present], true)) {
            RequiredDocument::query()->where('stock_cycle_id', $cycle->getKey())->where('category_key', $categoryKey)->delete();

            return;
        }

        RequiredDocument::query()->updateOrCreate(
            ['stock_cycle_id' => $cycle->getKey(), 'category_key' => $categoryKey],
            ['status' => $status, 'note' => $note],
        );
    }
}
