<?php

namespace App\Domain\Import\Actions;

use App\Domain\Import\Enums\ImporterType;
use App\Domain\Import\Enums\ImportRowAction;
use App\Domain\Import\Enums\ImportRunStatus;
use App\Domain\Import\Importers\CostImporter;
use App\Domain\Import\Importers\DocumentFolderImporter;
use App\Domain\Import\Importers\Importer;
use App\Domain\Import\Importers\VehicleImporter;
use App\Domain\Import\Models\ImportRow;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Support\RowResult;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Drives an import: the dry run executes every row for real inside one transaction and rolls
 * it back, so the report shows exactly what the import will do. The commit runs each row in
 * its own savepoint: a bad row is reported and the others still go in.
 */
class RunImport
{
    public static function importer(ImporterType $type): Importer
    {
        return match ($type) {
            ImporterType::Vehicles => app(VehicleImporter::class),
            ImporterType::Costs => app(CostImporter::class),
            ImporterType::Documents => app(DocumentFolderImporter::class),
        };
    }

    public function dryRun(ImportRun $run): ImportRun
    {
        return $this->run($run, dryRun: true);
    }

    public function commit(ImportRun $run): ImportRun
    {
        if ($run->status !== ImportRunStatus::Checked) {
            throw new BusinessRuleException(__('Check the file first (dry run), then import it.'));
        }

        return $this->run($run, dryRun: false);
    }

    private function run(ImportRun $run, bool $dryRun): ImportRun
    {
        $run->forceFill([
            'status' => $dryRun ? ImportRunStatus::DryRunning : ImportRunStatus::Committing,
            'started_at' => now(),
            'error' => null,
        ])->save();

        $importer = self::importer($run->importer);
        $local = $this->localCopy($run);
        $results = [];

        try {
            if ($dryRun) {
                DB::beginTransaction();
            }

            try {
                foreach ($importer->rows($run, $local) as $ref => $payload) {
                    $results[] = $this->importOne($importer, $run, new RowResult((string) $ref, $payload), $dryRun);
                }

                if (! $dryRun) {
                    $importer->afterCommit($run);
                }
            } finally {
                if ($dryRun) {
                    DB::rollBack();
                }
            }

            $this->storeResults($run, $results);

            $run->forceFill([
                'status' => $dryRun ? ImportRunStatus::Checked : ImportRunStatus::Committed,
                'summary' => $this->summary($results),
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $run->forceFill(['status' => ImportRunStatus::Failed, 'error' => $e->getMessage(), 'finished_at' => now()])->save();

            throw $e;
        } finally {
            @unlink($local);
        }

        return $run;
    }

    private function importOne(Importer $importer, ImportRun $run, RowResult $result, bool $dryRun): RowResult
    {
        try {
            DB::transaction(fn () => $importer->importRow($run, $result, $dryRun));
        } catch (BusinessRuleException|\InvalidArgumentException $e) {
            $result->action(ImportRowAction::Error)->note($e->getMessage());
            $result->created = [];
        } catch (Throwable $e) {
            report($e);
            $result->action(ImportRowAction::Error)->note(__('Unexpected error: :message', ['message' => $e->getMessage()]));
            $result->created = [];
        }

        return $result;
    }

    /**
     * @param  list<RowResult>  $results
     */
    private function storeResults(ImportRun $run, array $results): void
    {
        ImportRow::query()->where('import_run_id', $run->getKey())->delete();

        foreach (array_chunk($results, 200, true) as $chunk) {
            foreach ($chunk as $position => $result) {
                ImportRow::create([
                    'import_run_id' => $run->getKey(),
                    'position' => $position + 1,
                    'row_ref' => $result->ref,
                    'payload' => $result->payload,
                    'action' => $result->action,
                    'messages' => $result->messages === [] ? null : $result->messages,
                    'record_type' => $result->record?->getMorphClass(),
                    'record_id' => $result->record?->getKey(),
                    'created_records' => $result->created === [] ? null : $result->created,
                ]);
            }
        }
    }

    /**
     * @param  list<RowResult>  $results
     * @return array<string, int>
     */
    private function summary(array $results): array
    {
        $summary = array_fill_keys(array_map(fn (ImportRowAction $a): string => $a->value, ImportRowAction::cases()), 0);

        foreach ($results as $result) {
            $summary[$result->action->value]++;
        }

        $summary['total'] = count($results);

        return $summary;
    }

    private function localCopy(ImportRun $run): string
    {
        $local = tempnam(sys_get_temp_dir(), 'import');
        $stream = Storage::disk($run->disk)->readStream($run->path);
        file_put_contents($local, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $local;
    }
}
