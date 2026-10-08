<?php

namespace App\Domain\Import\Actions;

use App\Domain\Audit\MorphMap;
use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Import\Enums\ImportRowAction;
use App\Domain\Import\Enums\ImportRunStatus;
use App\Domain\Import\Models\ImportRow;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Removes what a committed import created, newest first, as long as nothing was built on it
 * since (costs, documents, sales or promises added by hand keep their records). Updates of
 * records that existed before the import are not undone; the report says so.
 */
class RollbackImport
{
    /**
     * @return array{removed: int, kept: int}
     */
    public function __invoke(ImportRun $run): array
    {
        if ($run->status !== ImportRunStatus::Committed) {
            throw new BusinessRuleException(__('Only an imported run can be rolled back.'));
        }

        $removed = 0;
        $kept = 0;

        $rows = ImportRow::query()->where('import_run_id', $run->getKey())->whereNotNull('created_records')->orderByDesc('position')->get();

        foreach ($rows as $row) {
            $notes = $row->messages ?? [];

            foreach (array_reverse($row->created_records ?? []) as [$type, $id]) {
                if ($this->remove($type, $id)) {
                    $removed++;
                } else {
                    $kept++;
                    $notes[] = __(':record kept: something was added to it after the import.', ['record' => MorphMap::label($type)]);
                }
            }

            $row->forceFill(['action' => ImportRowAction::RolledBack, 'messages' => $notes])->save();
        }

        $run->forceFill(['status' => ImportRunStatus::RolledBack, 'finished_at' => now()])->save();

        return ['removed' => $removed, 'kept' => $kept];
    }

    private function remove(string $type, string $id): bool
    {
        $class = Relation::getMorphedModel($type);

        if ($class === null) {
            return false;
        }

        /** @var Model|null $model */
        $model = $class::query()->find($id);

        if ($model === null) {
            return true;
        }

        if ($model instanceof StockCycle && $this->hasManualWork($model)) {
            return false;
        }

        try {
            DB::transaction(function () use ($model): void {
                if ($model instanceof Document) {
                    app(DeleteDocument::class)($model);

                    return;
                }

                $model->delete();
            });

            return true;
        } catch (Throwable) {
            return false; // still referenced (foreign key), so it stays
        }
    }

    private function hasManualWork(StockCycle $cycle): bool
    {
        return $cycle->costs()->exists()
            || $cycle->commitments()->exists()
            || $cycle->documents()->exists()
            || $cycle->sales()->where('legacy_ref', null)->exists();
    }
}
