<?php

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Enums\RequiredDocumentStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Documents\Models\RequiredDocument;
use App\Domain\Purchasing\Enums\SellerKind;
use App\Domain\Sales\Enums\PaymentType;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use Illuminate\Support\Collection;

/**
 * Which documents a vehicle file needs at its current stage (from the Excel's practice):
 * purchase contract and registration copy always, supplier invoice when bought from a
 * company, sales contract and invoice once sold, leasing contract for a leasing sale.
 * Present = a document of that category is in the file; otherwise the manual status
 * (requested, not required) or "missing".
 */
class RequiredDocumentsChecklist
{
    /**
     * @return Collection<int, array{key: string, label: string, status: RequiredDocumentStatus, note: string|null}>
     */
    public function __invoke(StockCycle $cycle): Collection
    {
        $keys = $this->requiredKeys($cycle);

        if ($keys === []) {
            return collect();
        }

        $categories = DocumentCategory::query()->whereIn('key', $keys)->get()->keyBy('key');
        $presentCategoryIds = Document::query()->linkedTo($cycle)->pluck('category_id')->unique()->all();
        $manual = RequiredDocument::query()->where('stock_cycle_id', $cycle->getKey())->get()->keyBy('category_key');

        return collect($keys)->map(function (string $key) use ($categories, $presentCategoryIds, $manual): array {
            /** @var DocumentCategory|null $category */
            $category = $categories->get($key);
            /** @var RequiredDocument|null $override */
            $override = $manual->get($key);

            $status = match (true) {
                $category !== null && in_array($category->getKey(), $presentCategoryIds, true) => RequiredDocumentStatus::Present,
                $override !== null => $override->status,
                default => RequiredDocumentStatus::Missing,
            };

            return [
                'key' => $key,
                'label' => $category->name ?? $key,
                'status' => $status,
                'note' => $override?->note,
            ];
        })->values();
    }

    public function missingCount(StockCycle $cycle): int
    {
        return $this($cycle)->filter(fn (array $item): bool => $item['status'] === RequiredDocumentStatus::Missing)->count();
    }

    /**
     * @return list<string>
     */
    public function requiredKeys(StockCycle $cycle): array
    {
        if (in_array($cycle->status, [StockCycleStatus::InReview, StockCycleStatus::Cancelled], true)) {
            return [];
        }

        $keys = ['purchase_contract', 'registration'];
        $purchase = $cycle->purchase()->first();

        if ($purchase !== null && $purchase->seller_kind !== SellerKind::Private) {
            $keys[] = 'supplier_invoice';
        }

        if (in_array($cycle->status, [StockCycleStatus::Sold, StockCycleStatus::Delivered, StockCycleStatus::Archived], true)) {
            $keys[] = 'sales_contract';
            $keys[] = 'invoice';

            if ($cycle->activeSale()->first()?->payment_type === PaymentType::Leasing) {
                $keys[] = 'leasing_contract';
            }
        }

        return $keys;
    }
}
