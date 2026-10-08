<?php

use App\Domain\Documents\Models\Document;
use App\Domain\Import\Actions\CreateImportRun;
use App\Domain\Import\Actions\RunImport;
use App\Domain\Import\Enums\ImporterType;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Support\SpreadsheetReader;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Support\ManualTestPack;

/*
 * The sample files of docs/manual-test/README.md must do exactly what that guide promises.
 */

beforeEach(function () {
    app()->setLocale('en');
    $this->dir = sys_get_temp_dir().'/manual-test-'.uniqid();
    (new ManualTestPack)->build($this->dir);

    $this->tenant = makeDealer(['name' => 'Demo Garage Bern', 'slug' => 'demo-garage-bern']);
    $this->actingAs(makeMember($this->tenant, Role::Administrator));
    tenantContext()->set($this->tenant);
});

function packImport(ImporterType $type, string $file, bool $commit = true, ?string $sheet = null): ImportRun
{
    $path = test()->dir.'/'.$file;
    $mapping = $type === ImporterType::Documents
        ? []
        : CreateImportRun::guessMapping(RunImport::importer($type)->guesses(), (new SpreadsheetReader($path, $file))->headers($sheet));

    $run = app(RunImport::class)->dryRun(app(CreateImportRun::class)($path, $file, $type, $mapping, array_filter(['sheet' => $sheet])));

    return $commit ? app(RunImport::class)->commit($run->refresh()) : $run;
}

it('follows the manual test workflow: vehicles, costs, documents', function () {
    // Step 1: vehicles. Seven rows, all create; the sheet "Fahrzeuge" is picked, not "Info".
    $check = packImport(ImporterType::Vehicles, '1-Fahrzeuge.xlsx', commit: false, sheet: 'Fahrzeuge');
    expect($check->summary['create'])->toBe(7)
        ->and($check->summary['error'])->toBe(0)
        ->and(Vehicle::count())->toBe(0);

    $run = app(RunImport::class)->commit($check->refresh());

    expect($run->summary['create'])->toBe(7)
        ->and(Vehicle::count())->toBe(6) // the BMW appears twice: bought back
        ->and(StockCycle::count())->toBe(7);

    $bmw = StockCycle::where('legacy_ref', '201')->firstOrFail();
    expect($bmw->number)->toBe('2025-0201')
        ->and($bmw->file_year)->toBe(2025)
        ->and($bmw->sold_on->toDateString())->toBe('2026-01-15')
        ->and($bmw->status)->toBe(StockCycleStatus::Delivered)
        ->and(StockCycle::where('legacy_ref', '204')->first()->status)->toBe(StockCycleStatus::Sold)
        ->and(StockCycle::where('legacy_ref', '207')->first()->status)->toBe(StockCycleStatus::Cancelled)
        ->and(StockCycle::where('legacy_ref', '205')->first()->vehicle_id)->toBe($bmw->vehicle_id);

    // Running it again changes nothing.
    $again = packImport(ImporterType::Vehicles, '1-Fahrzeuge.xlsx', sheet: 'Fahrzeuge');
    expect($again->summary['create'])->toBe(0)
        ->and(StockCycle::count())->toBe(7);

    // Step 2: costs. Five good rows, two refused (unknown vehicle, missing date).
    $costs = packImport(ImporterType::Costs, '2-Kosten.xlsx');
    expect($costs->summary['create'])->toBe(5)
        ->and($costs->summary['error'])->toBe(2)
        ->and(Cost::count())->toBe(5)
        ->and(Cost::whereHas('category', fn ($q) => $q->where('key', 'unclear'))->count())->toBe(1);

    // Step 3: documents. Eight files go in, the duplicate contract is skipped, two land in the inbox.
    $docs = packImport(ImporterType::Documents, '3-Dokumente.zip');
    expect($docs->summary['create'])->toBe(7)
        ->and($docs->summary['skip'])->toBe(1)
        ->and($docs->summary['unassigned'])->toBe(2)
        ->and($docs->summary['error'])->toBe(0)
        ->and(Document::count())->toBe(9)
        ->and($bmw->documents()->count())->toBe(4);
});

it('writes a scan that only Tesseract can read', function () {
    expect(getimagesize($this->dir.'/scan-kaufvertrag.png')[0])->toBe(1680)
        ->and(file_get_contents($this->dir.'/scan-kaufvertrag.png'))->not->toContain('Corolla');
});
