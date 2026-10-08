<?php

use App\Domain\Import\Enums\ImporterType;
use App\Domain\Import\Enums\ImportRunStatus;
use App\Domain\Import\Models\ImportPreset;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Actions\ExportVehicleList;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Filament\App\Resources\ImportRuns\ImportRunResource;
use App\Filament\App\Resources\ImportRuns\Pages\CreateImportRun;
use App\Filament\App\Resources\ImportRuns\Pages\ListImportRuns;
use App\Filament\App\Resources\ImportRuns\Pages\ViewImportRun;
use App\Filament\App\Resources\StockCycles\Pages\ListStockCycles;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

beforeEach(function () {
    app()->setLocale('en');
    $this->tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a']);
    $this->admin = makeMember($this->tenant, Role::Administrator);
});

function stockCsv(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('Fahrzeuge.csv', implode("\n", [
        'Nr.;Stammnummer;Fahrzeug;Status;EK Datum;EK CHF;Verkäufer',
        '7;683.737.537;BMW X3 30i;Bestand;14.07.2026;21500;Reflex Automobiles Sàrl',
        '8;;Toyota Yaris;Bestand;15.07.2026;8000;Peter Keller',
    ]));
}

it('keeps imports for administrators', function () {
    useAppPanel($this->tenant, makeMember($this->tenant, Role::Sales));

    expect(ImportRunResource::canViewAny())->toBeFalse()
        ->and(ImportRunResource::canCreate())->toBeFalse();

    useAppPanel($this->tenant, $this->admin);

    expect(ImportRunResource::canViewAny())->toBeTrue();
});

it('recognises the columns, checks the file and imports it after confirmation', function () {
    useAppPanel($this->tenant, $this->admin);

    $page = Livewire::test(CreateImportRun::class)
        ->fillForm(['importer' => ImporterType::Vehicles->value])
        ->set('data.file', stockCsv())
        ->assertFormSet([
            'mapping.legacy_ref' => 'Nr.',
            'mapping.stammnummer' => 'Stammnummer',
            'mapping.label' => 'Fahrzeug',
            'mapping.purchased_on' => 'EK Datum',
            'mapping.seller' => 'Verkäufer',
            'mapping.vin' => null,
        ])
        ->fillForm(['save_preset_as' => 'Mein Excel'])
        ->call('create')
        ->assertHasNoFormErrors();

    $run = ImportRun::sole();

    // The check ran (queue is synchronous in tests) and changed nothing.
    expect($run->status)->toBe(ImportRunStatus::Checked)
        ->and($run->summary['create'])->toBe(2)
        ->and(Vehicle::count())->toBe(0)
        ->and(ImportPreset::sole()->name)->toBe('Mein Excel');

    $page->assertRedirect(ImportRunResource::getUrl('view', ['record' => $run]));

    Livewire::test(ViewImportRun::class, ['record' => $run->getKey()])
        ->assertSee('Row 2')
        ->assertActionVisible('commit')
        ->assertActionHidden('rollback')
        ->callAction('commit')
        ->assertNotified('Import started.');

    expect($run->refresh()->status)->toBe(ImportRunStatus::Committed)
        ->and(StockCycle::count())->toBe(2);

    Livewire::test(ViewImportRun::class, ['record' => $run->getKey()])
        ->assertActionHidden('commit')
        ->callAction('rollback')
        ->assertNotified('Rolled back: 8 removed, 0 kept.');

    expect($run->refresh()->status)->toBe(ImportRunStatus::RolledBack)
        ->and(StockCycle::count())->toBe(0)
        ->and(Vehicle::count())->toBe(0);

    Livewire::test(ListImportRuns::class)->assertCanSeeTableRecords([$run]);
});

it('exports the stock list in a format the import reads back', function () {
    useAppPanel($this->tenant, $this->admin);

    Livewire::test(CreateImportRun::class)
        ->fillForm(['importer' => ImporterType::Vehicles->value])
        ->set('data.file', stockCsv())
        ->call('create');
    Livewire::test(ViewImportRun::class, ['record' => ImportRun::sole()->getKey()])->callAction('commit');

    $path = app(ExportVehicleList::class)();
    $csv = file_get_contents($path);

    expect($csv)->toStartWith("\u{FEFF}Nr.;Stammnummer;VIN;Marke;Modell;Status")
        ->and($csv)->toContain('2026-0007;683737537;;BMW;X3;"Ready for sale";14.07.2026;21500.00;"Direct purchase";"Reflex Automobiles Sàrl"');

    // Re-importing the export changes nothing and duplicates nothing.
    Livewire::test(CreateImportRun::class)
        ->fillForm(['importer' => ImporterType::Vehicles->value])
        ->set('data.file', UploadedFile::fake()->createWithContent('export.csv', $csv))
        ->call('create');

    $recheck = ImportRun::latest('created_at')->first();
    expect($recheck->summary['update'])->toBe(2)
        ->and($recheck->summary['error'])->toBe(0);

    Livewire::test(ListStockCycles::class)
        ->assertActionVisible('exportList')
        ->callAction('exportList')
        ->assertFileDownloaded('vehicles-'.now()->format('Y-m-d').'.csv');
});
