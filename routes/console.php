<?php

use App\Domain\Documents\Enums\OcrStatus;
use App\Domain\Documents\Jobs\RunOcr;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Import\Actions\CreateImportRun;
use App\Domain\Import\Actions\RunImport;
use App\Domain\Import\Enums\ImporterType;
use App\Domain\Import\Models\ImportPreset;
use App\Domain\Import\Support\SpreadsheetReader;
use App\Domain\Operations\Models\RestoreDrill;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Vehicles\Actions\ArchiveDeliveredCycles;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
 * Commands that touch business data run once per dealer, inside that dealer's tenant context,
 * so Row-Level Security applies to them exactly as it does to a web request.
 */
Artisan::command('vehicles:archive', function (TenantContext $context, ArchiveDeliveredCycles $archive): void {
    $tenants = $context->bypass(fn () => Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->get());

    foreach ($tenants as $tenant) {
        $count = $context->run($tenant, fn (): int => $archive());

        if ($count > 0) {
            $this->info("{$tenant->name}: {$count} vehicle file(s) archived");
        }
    }
})->purpose('Archive vehicle files delivered longer ago than the dealer\'s archive period');

Schedule::command('vehicles:archive')->dailyAt('02:45');

/*
 * Reads the text of every scan still waiting for it, for all dealers. For local work without a
 * queue worker, or to catch up after the worker was down.
 */
Artisan::command('documents:ocr', function (TenantContext $context): void {
    $tenants = $context->bypass(fn () => Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->get());

    foreach ($tenants as $tenant) {
        $done = $context->run($tenant, function (): int {
            $ids = DocumentVersion::query()->where('ocr_status', OcrStatus::Pending)->pluck('id');

            foreach ($ids as $id) {
                app()->call([new RunOcr($id), 'handle']);
            }

            return $ids->count();
        });

        if ($done > 0) {
            $this->info("{$tenant->name}: {$done} document(s) processed");
        }
    }
})->purpose('Read the text of all documents that are still waiting for it');

/*
 * Large imports (e.g. a 1 GB document folder) from the server's disk instead of a browser upload.
 * Checks first; imports only with --commit, and only if the check found no errors (or --force).
 */
Artisan::command('import:run {dealer : dealer slug} {importer : vehicles, costs or documents} {file} {--preset= : name of a saved mapping preset} {--sheet=} {--commit} {--force}', function (TenantContext $context, RunImport $runImport): int {
    $tenant = $context->bypass(fn () => Tenant::query()->where('slug', $this->argument('dealer'))->first());

    if ($tenant === null) {
        $this->error('No dealer with slug '.$this->argument('dealer'));

        return 1;
    }

    $type = ImporterType::from((string) $this->argument('importer'));
    $file = (string) $this->argument('file');

    return $context->run($tenant, function () use ($type, $file, $runImport): int {
        $preset = $this->option('preset') ? ImportPreset::query()->where('importer', $type)->where('name', $this->option('preset'))->firstOrFail() : null;
        $options = array_filter([...($preset?->options ?? []), 'sheet' => $this->option('sheet')]);
        $mapping = $preset?->mapping ?? [];

        if ($mapping === [] && $type !== ImporterType::Documents) {
            $importer = RunImport::importer($type);
            $mapping = CreateImportRun::guessMapping($importer->guesses(), (new SpreadsheetReader($file, basename($file)))->headers($options['sheet'] ?? null));
            $this->line('Columns recognised: '.implode(', ', array_keys(array_filter($mapping))));
        }

        $run = app(CreateImportRun::class)($file, basename($file), $type, $mapping, $options);
        $runImport->dryRun($run);
        $this->table(['result', 'rows'], collect($run->summary)->map(fn ($count, $action) => [$action, $count])->values()->all());

        if (! $this->option('commit')) {
            $this->info('Checked only. Run again with --commit to import.');

            return 0;
        }

        if (($run->summary['error'] ?? 0) > 0 && ! $this->option('force')) {
            $this->error('The check found errors; fix them or use --force to import the other rows.');

            return 1;
        }

        $runImport->commit($run->refresh());
        $this->info('Imported: '.json_encode($run->summary));

        return 0;
    });
})->purpose('Check and import a vehicles / costs Excel or a document folder ZIP for one dealer');

/*
 * Called by deploy/restore-test.sh after each monthly restore drill (acceptance test 13).
 */
Artisan::command('backup:record-drill {status : ok or failed} {--file=} {--tenants=} {--audit-rows=} {--rls-tables=} {--duration=} {--message=}', function (): int {
    $status = (string) $this->argument('status');

    if (! in_array($status, [RestoreDrill::STATUS_OK, RestoreDrill::STATUS_FAILED], true)) {
        $this->error('Status must be ok or failed.');

        return 1;
    }

    $toInt = fn (mixed $value): ?int => $value === null || $value === '' ? null : (int) $value;

    RestoreDrill::create([
        'status' => $status,
        'backup_file' => $this->option('file'),
        'tenants' => $toInt($this->option('tenants')),
        'audit_rows' => $toInt($this->option('audit-rows')),
        'rls_tables' => $toInt($this->option('rls-tables')),
        'duration_seconds' => $toInt($this->option('duration')),
        'message' => $this->option('message'),
        'ran_at' => now(),
    ]);

    $this->info("Restore drill recorded: {$status}");

    return 0;
})->purpose('Record the result of a restore drill');
