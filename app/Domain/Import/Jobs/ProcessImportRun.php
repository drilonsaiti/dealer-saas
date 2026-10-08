<?php

namespace App\Domain\Import\Jobs;

use App\Domain\Import\Actions\RunImport;
use App\Domain\Import\Models\ImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Checks (dry run) or imports a run in the queue, as the dealer that started it.
 */
class ProcessImportRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly string $runId, public readonly bool $commit)
    {
        $connection = config('dealer.imports.queue_connection');

        if (is_string($connection) && $connection !== '') {
            $this->onConnection($connection);
        }
    }

    public function handle(RunImport $runImport): void
    {
        $run = ImportRun::query()->find($this->runId);

        if ($run === null) {
            return;
        }

        $this->commit ? $runImport->commit($run) : $runImport->dryRun($run);
    }
}
