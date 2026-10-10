<?php

namespace App\Domain\Preparation\Actions;

use App\Domain\Documents\Actions\InstallDefaultDocumentCategories;
use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Preparation\Models\ConditionReport;
use App\Domain\Preparation\Models\Damage;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Records a condition report: a rating per area, a summary and the damages, each with
 * optional photos (filed as photo documents of the vehicle file, linked to the damage).
 */
class RecordConditionReport
{
    public function __construct(private readonly StoreDocument $store) {}

    /**
     * @param  array<string, mixed>  $data  reported_on, mileage, summary, items (area => [rating, note])
     * @param  list<array{area: string, kind: string, severity?: string, notes?: string|null, photos?: list<UploadedFile|string>}>  $damages
     */
    public function __invoke(StockCycle $cycle, array $data, array $damages = []): ConditionReport
    {
        if ($cycle->isLocked()) {
            throw new BusinessRuleException(__('This vehicle file is closed and cannot be changed.'));
        }

        return DB::transaction(function () use ($cycle, $data, $damages): ConditionReport {
            $report = ConditionReport::create([
                'stock_cycle_id' => $cycle->getKey(),
                'reported_on' => $data['reported_on'] ?? now()->toDateString(),
                'user_id' => Auth::id(),
                'mileage' => $data['mileage'] ?? null,
                'summary' => $data['summary'] ?? null,
                'items' => $data['items'] ?? null,
            ]);

            foreach ($damages as $row) {
                $damage = Damage::create([
                    'condition_report_id' => $report->getKey(),
                    'stock_cycle_id' => $cycle->getKey(),
                    'area' => $row['area'],
                    'kind' => $row['kind'],
                    'severity' => $row['severity'] ?? 'minor',
                    'notes' => $row['notes'] ?? null,
                ]);

                foreach ($row['photos'] ?? [] as $photo) {
                    $this->photo($cycle, $damage, $photo);
                }
            }

            return $report->load('damages');
        });
    }

    private function photo(StockCycle $cycle, Damage $damage, UploadedFile|string $file): void
    {
        $category = DocumentCategory::query()->where('key', 'photo')->first();

        if ($category === null) {
            app(InstallDefaultDocumentCategories::class)();
            $category = DocumentCategory::query()->where('key', 'photo')->firstOrFail();
        }

        ($this->store)($file, $category, ['title' => __('Damage').': '.$damage->label(), 'document_on' => now()->toDateString()], [$cycle, $damage]);
    }
}
