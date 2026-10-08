<?php

use App\Domain\Documents\Models\Document;
use App\Domain\Import\Actions\CreateImportRun;
use App\Domain\Import\Actions\RollbackImport;
use App\Domain\Import\Actions\RunImport;
use App\Domain\Import\Enums\ImporterType;
use App\Domain\Import\Enums\ImportRowAction;
use App\Domain\Import\Enums\ImportRunStatus;
use App\Domain\Import\Importers\DocumentFolderImporter;
use App\Domain\Import\Models\ImportPreset;
use App\Domain\Import\Models\ImportRow;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Support\Normalize;
use App\Domain\Import\Support\SpreadsheetReader;
use App\Domain\Parties\Models\Party;
use App\Domain\Purchasing\Enums\CostStatus;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Settings\Actions\IssueNumber;
use App\Domain\Settings\Enums\NumberSequenceKey;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Support\BusinessRuleException;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    app()->setLocale('en');
    Carbon::setTestNow('2026-10-08 10:00:00');
    $this->tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a']);
    $this->user = makeMember($this->tenant, Role::Administrator);
    $this->actingAs($this->user);
    tenantContext()->set($this->tenant);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * @param  list<list<string|int|null>>  $rows  first row = headers
 */
function csvFile(array $rows, string $delimiter = ';'): string
{
    $path = tempnam(sys_get_temp_dir(), 'csv').'.csv';
    $handle = fopen($path, 'wb');

    foreach ($rows as $row) {
        fputcsv($handle, $row, $delimiter, escape: '');
    }

    fclose($handle);

    return $path;
}

/**
 * Upload + check, with the column mapping guessed from the headers.
 */
function checkImport(ImporterType $type, string $path, string $name, array $options = []): ImportRun
{
    $mapping = $type === ImporterType::Documents
        ? []
        : CreateImportRun::guessMapping(RunImport::importer($type)->guesses(), (new SpreadsheetReader($path, $name))->headers());

    $run = app(CreateImportRun::class)($path, $name, $type, $mapping, $options);

    return app(RunImport::class)->dryRun($run);
}

function importNow(ImporterType $type, string $path, string $name, array $options = []): ImportRun
{
    return app(RunImport::class)->commit(checkImport($type, $path, $name, $options)->refresh());
}

/** A stock list shaped like Aziri's "Fahrzeuge" sheet. */
function stockList(): string
{
    return csvFile([
        ['Nr.', 'Stammnummer', 'Fahrzeug', 'Status', 'EK Datum', 'EK CHF', 'Einkaufsart', 'Verkäufer', 'VK Datum', 'VK CHF', 'Käufer', 'KM Einkauf'],
        ['12', '683.737.537', 'BMW X3 30i Blau', 'Verkauft', '20.11.2025', "21'500.00", 'Direkt', 'Reflex Automobiles Sàrl', '15.01.2026', "26'900.00", 'Anna Muster', '79310'],
        ['13', '412.118.903', 'VW Golf 1.4 TSI', 'Bestand', '2026-03-02', '9800', 'Eintausch', 'Peter Keller', null, null, null, '120500'],
        ['14', '305.221.774', 'Audi A4 Avant', 'Bestand', '2026-05-10', '15400', 'Direkt', 'Reflex Automobiles Sàrl', null, null, null, '88000'],
        [null, null, null, null, null, null, null, null, null, null, null, null],
    ]);
}

it('guesses the column mapping from the headers, ignoring case and accents', function () {
    $mapping = CreateImportRun::guessMapping(
        RunImport::importer(ImporterType::Vehicles)->guesses(),
        ['NR.', 'stammnummer', 'Verkaufer', 'EK Datum', 'Something else'],
    );

    expect($mapping['legacy_ref'])->toBe('NR.')
        ->and($mapping['stammnummer'])->toBe('stammnummer')
        ->and($mapping['seller'])->toBe('Verkaufer')
        ->and($mapping['purchased_on'])->toBe('EK Datum')
        ->and($mapping['vin'])->toBeNull();
});

it('reads Swiss numbers and dates', function () {
    expect(Normalize::money("21'500.50"))->toBe(2150050)
        ->and(Normalize::money('CHF 9’800.–'))->toBe(980000)
        ->and(Normalize::date('15.01.2026'))->toBe('2026-01-15')
        ->and(Normalize::date('2026-03-02'))->toBe('2026-03-02')
        ->and(Normalize::integer("79'310 km"))->toBe(79310);
});

it('checks a file without changing any data', function () {
    $run = checkImport(ImporterType::Vehicles, stockList(), 'Fahrzeuge.csv');

    expect($run->status)->toBe(ImportRunStatus::Checked)
        ->and($run->summary['create'])->toBe(3)
        ->and($run->summary['total'])->toBe(3) // the empty line is not a row
        ->and($run->summary['error'])->toBe(0)
        ->and(Vehicle::count())->toBe(0)
        ->and(StockCycle::count())->toBe(0)
        ->and(Party::count())->toBe(0)
        ->and(ImportRow::where('import_run_id', $run->id)->count())->toBe(3);
});

it('refuses to import a file that was not checked first', function () {
    $run = app(CreateImportRun::class)(stockList(), 'Fahrzeuge.csv', ImporterType::Vehicles);

    app(RunImport::class)->commit($run);
})->throws(BusinessRuleException::class);

it('imports vehicles, files, purchases, sales and contacts (acceptance test 1)', function () {
    $run = importNow(ImporterType::Vehicles, stockList(), 'Fahrzeuge.csv');

    expect($run->status)->toBe(ImportRunStatus::Committed)
        ->and($run->summary['create'])->toBe(3)
        ->and(Vehicle::count())->toBe(3)
        ->and(StockCycle::count())->toBe(3)
        ->and(Purchase::count())->toBe(3)
        ->and(Sale::count())->toBe(1);

    // "Reflex Automobiles Sàrl" in two rows is one supplier.
    expect(Party::count())->toBe(3)
        ->and(Party::where('company_name', 'Reflex Automobiles Sàrl')->count())->toBe(1);

    $golf = StockCycle::where('legacy_ref', '13')->firstOrFail();
    expect($golf->status)->toBe(StockCycleStatus::ReadyForSale)
        ->and($golf->number)->toBe('2026-0013')
        ->and($golf->vehicle->make)->toBe('VW')
        ->and($golf->vehicle->model)->toBe('Golf')
        ->and($golf->purchase->price_rp)->toBe(980000)
        ->and($golf->purchase->purchase_type->value)->toBe('trade_in')
        ->and($golf->statusHistory()->latest('id')->first()?->reason)->toBe('Import');
});

it('can run the same list again without creating duplicates (acceptance test 1)', function () {
    importNow(ImporterType::Vehicles, stockList(), 'Fahrzeuge.csv');
    $second = importNow(ImporterType::Vehicles, stockList(), 'Fahrzeuge.csv');

    expect($second->summary['create'])->toBe(0)
        ->and($second->summary['update'])->toBe(3)
        ->and(Vehicle::count())->toBe(3)
        ->and(StockCycle::count())->toBe(3)
        ->and(Purchase::count())->toBe(3)
        ->and(Sale::count())->toBe(1)
        ->and(Party::count())->toBe(3)
        ->and(Vehicle::whereNotNull('stammnummer')->distinct()->count('stammnummer'))->toBe(3);
});

it('keeps a car bought in 2025 and sold in 2026 in one 2025 file (acceptance test 2)', function () {
    importNow(ImporterType::Vehicles, stockList(), 'Fahrzeuge.csv');

    $bmw = StockCycle::where('legacy_ref', '12')->with('sales')->firstOrFail();

    expect(StockCycle::where('vehicle_id', $bmw->vehicle_id)->count())->toBe(1)
        ->and($bmw->file_year)->toBe(2025)
        ->and($bmw->number)->toBe('2025-0012')
        ->and($bmw->purchased_on->toDateString())->toBe('2025-11-20')
        ->and($bmw->sold_on->toDateString())->toBe('2026-01-15')
        // Sold more than 30 days ago without handover date: treated as handed over.
        ->and($bmw->status)->toBe(StockCycleStatus::Delivered)
        ->and($bmw->sales)->toHaveCount(1)
        ->and($bmw->sales->first()->status)->toBe(SaleStatus::Delivered)
        ->and($bmw->sales->first()->price_rp)->toBe(2690000)
        ->and($bmw->sales->first()->buyer->displayName())->toBe('Anna Muster');
});

it('turns a buy-back into a second file on the same vehicle', function () {
    $path = csvFile([
        ['Nr.', 'Stammnummer', 'Fahrzeug', 'Status', 'EK Datum', 'EK CHF', 'VK Datum', 'VK CHF', 'Käufer'],
        ['20', '683737537', 'BMW X3', 'Verkauft', '2025-02-01', '20000', '2025-03-01', '25000', 'Anna Muster'],
        ['21', '683737537', 'BMW X3', 'Bestand', '2026-04-01', '18000', null, null, null],
    ]);

    importNow(ImporterType::Vehicles, $path, 'buyback.csv');

    expect(Vehicle::count())->toBe(1)
        ->and(StockCycle::count())->toBe(2)
        ->and(StockCycle::where('legacy_ref', '21')->first()->status)->toBe(StockCycleStatus::ReadyForSale);
});

it('reports problems per row and imports the other rows', function () {
    $path = csvFile([
        ['Nr.', 'Stammnummer', 'Fahrzeug', 'Status', 'EK Datum', 'EK CHF', 'Einkaufsart'],
        ['30', '12345', 'Lada Niva', 'Unbekannt', '2026-06-01', null, 'ungeklärt'],
        ['31', '412118903', 'Seat Ibiza', 'Bestand', '2026-06-02', '7000', null],
        ['32', '412118903', 'Seat Ibiza', 'Bestand', '2026-06-03', '7100', null],
    ]);

    $run = checkImport(ImporterType::Vehicles, $path, 'issues.csv');
    $rows = ImportRow::where('import_run_id', $run->id)->orderBy('position')->get();

    expect($rows[0]->action)->toBe(ImportRowAction::Create)
        ->and($rows[0]->messages)->toContain(
            'Stammnummer "12345" is not valid; imported without it.',
            'Make not recognised in "Lada Niva"; please complete it.',
            'Status "unbekannt" not recognised; derived from the dates.',
            'Purchase price missing; recorded as 0.00.',
            'Purchase type unclear: please clarify.',
        )
        ->and($rows[1]->action)->toBe(ImportRowAction::Create)
        // The same car twice in stock at once: the second row is refused, not merged.
        ->and($rows[2]->action)->toBe(ImportRowAction::Error)
        ->and($rows[2]->messages)->toBe(['This vehicle is already in stock with another open file; check the status of both rows.']);

    $run = app(RunImport::class)->commit($run->refresh());

    expect($run->summary['create'])->toBe(2)
        ->and($run->summary['error'])->toBe(1)
        ->and(StockCycle::count())->toBe(2);
});

it('reads Excel files with real date cells', function () {
    $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Nr.', 'Stammnummer', 'Fahrzeug', 'EK Datum', 'EK CHF']));
    $writer->addRow(Row::fromValues(['40', '305221774', 'Skoda Octavia', new DateTimeImmutable('2026-02-14'), 12500.5]));
    $writer->close();

    importNow(ImporterType::Vehicles, $path, 'Fahrzeuge.xlsx');

    $cycle = StockCycle::where('legacy_ref', '40')->firstOrFail();

    expect($cycle->purchased_on->toDateString())->toBe('2026-02-14')
        ->and($cycle->purchase->price_rp)->toBe(1250050)
        ->and($cycle->vehicle->make)->toBe('Skoda');
});

it('continues new file numbers after the highest imported one', function () {
    importNow(ImporterType::Vehicles, stockList(), 'Fahrzeuge.csv');

    expect(app(IssueNumber::class)(NumberSequenceKey::StockCycle))->toBe('2026-0015');
});

it('rolls an import back, but keeps files that were worked on since', function () {
    $run = importNow(ImporterType::Vehicles, stockList(), 'Fahrzeuge.csv');

    // Work added by hand after the import.
    $audi = StockCycle::where('legacy_ref', '14')->firstOrFail();
    Cost::create([
        'stock_cycle_id' => $audi->id,
        'category_id' => CostCategory::where('key', 'repair')->value('id'),
        'incurred_on' => '2026-06-01',
        'gross_rp' => 50000,
    ]);

    $result = app(RollbackImport::class)($run);

    expect($run->refresh()->status)->toBe(ImportRunStatus::RolledBack)
        ->and($result['kept'])->toBeGreaterThan(0)
        ->and(StockCycle::pluck('legacy_ref')->all())->toBe(['14'])
        ->and(Vehicle::count())->toBe(1)
        ->and(Sale::count())->toBe(0)
        ->and(Party::where('first_name', 'Anna')->exists())->toBeFalse()
        ->and(ImportRow::where('import_run_id', $run->id)->where('action', ImportRowAction::RolledBack)->count())->toBe(3);
});

it('imports costs as drafts linked to the vehicle file', function () {
    importNow(ImporterType::Vehicles, stockList(), 'Fahrzeuge.csv');

    $path = csvFile([
        ['Kosten-ID', 'Fahrzeug Nr.', 'Datum', 'Betrag CHF', 'Kategorie', 'Beschreibung', 'Lieferant'],
        ['K0001', '13', '05.03.2026', '450.00', 'Transport', 'Abholung Bern', 'Müller Transporte GmbH'],
        ['K0002', '13', '10.03.2026', "1'180.40", null, 'Diverses', null],
        ['K0003', '99', '10.03.2026', '100', 'Reparatur', null, null],
        ['K0004', '14', null, '100', 'Reparatur', null, null],
    ]);

    $run = importNow(ImporterType::Costs, $path, 'Kosten.csv');
    $golf = StockCycle::where('legacy_ref', '13')->firstOrFail();

    expect($run->summary['create'])->toBe(2)
        ->and($run->summary['error'])->toBe(2)
        ->and($golf->costs()->count())->toBe(2);

    $transport = Cost::where('legacy_ref', 'K0001')->with(['category', 'supplier'])->firstOrFail();
    $unclear = Cost::where('legacy_ref', 'K0002')->with('category')->firstOrFail();

    expect($transport->status)->toBe(CostStatus::Draft)
        ->and($transport->category->key)->toBe('transport')
        ->and($transport->gross_rp)->toBe(45000)
        ->and($transport->supplier->company_name)->toBe('Müller Transporte GmbH')
        ->and($unclear->category->key)->toBe('unclear')
        ->and($unclear->gross_rp)->toBe(118040);

    // Running it again changes the drafts, never duplicates them.
    importNow(ImporterType::Costs, $path, 'Kosten.csv');
    expect(Cost::count())->toBe(2);
});

it('imports a document folder ZIP into the right vehicle files', function () {
    importNow(ImporterType::Vehicles, stockList(), 'Fahrzeuge.csv');

    $zipPath = tempnam(sys_get_temp_dir(), 'zip').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addEmptyDir('2025/683737537_BMW_X3/01_Ankauf');
    $zip->addFromString('2025/683737537_BMW_X3/01_Ankauf/2025-11-20_683737537_Kaufvertrag_D0001.pdf', '%PDF-1.4 purchase contract');
    $zip->addFromString('2025/683737537_BMW_X3/04_Verkauf/2026-01-15_683737537_Kaufvertrag_D0002.pdf', '%PDF-1.4 sales contract');
    $zip->addFromString('2025/683737537_BMW_X3/04_Verkauf/kopie.pdf', '%PDF-1.4 sales contract');
    $zip->addFromString('Unsortiert/UNDATIERT_999999999_Rechnung_D0003.pdf', '%PDF-1.4 someone else');
    $zip->addFromString('__MACOSX/._junk', 'x');
    $zip->addFromString('.DS_Store', 'x');
    $zip->close();

    $run = checkImport(ImporterType::Documents, $zipPath, 'Ordner.zip');

    expect($run->summary['total'])->toBe(4)
        ->and(Document::count())->toBe(0);

    $run = app(RunImport::class)->commit($run->refresh());
    $bmw = StockCycle::where('legacy_ref', '12')->firstOrFail();

    expect($run->summary['create'])->toBe(2)
        ->and($run->summary['skip'])->toBe(1)
        ->and($run->summary['unassigned'])->toBe(1)
        ->and($bmw->documents()->with('category')->get()->pluck('category.key')->sort()->values()->all())->toBe(['purchase_contract', 'sales_contract'])
        ->and(Document::where('legacy_ref', 'D0003')->first()->links()->count())->toBe(0);
});

it('parses file names with a custom pattern', function () {
    $parsed = app(DocumentFolderImporter::class)->parse('2026/03_Werkstatt/D0028-Rechnung-Service-2026-07-14-683.737.537.pdf', '{id}-{type}-{date}-{stammnummer}');

    expect($parsed)->toBe([
        'date' => '2026-07-14',
        'stammnummer' => '683737537',
        'type' => 'Rechnung Service',
        'id' => 'D0028',
        'folder' => '03',
    ]);
});

it('saves the column mapping as a preset for the next import', function () {
    $run = app(CreateImportRun::class)(stockList(), 'Fahrzeuge.csv', ImporterType::Vehicles, ['legacy_ref' => 'Nr.'], ['sheet' => null], 'Aziri Excel');

    expect($run->preset?->name)->toBe('Aziri Excel')
        ->and(ImportPreset::where('importer', ImporterType::Vehicles)->first()->mapping)->toBe(['legacy_ref' => 'Nr.']);
});

it('imports from the command line, checking first', function () {
    $path = stockList();

    $this->artisan('import:run', ['dealer' => 'garage-a', 'importer' => 'vehicles', 'file' => $path])
        ->expectsOutputToContain('Checked only')
        ->assertSuccessful();

    expect(asTenant($this->tenant, fn () => Vehicle::count()))->toBe(0);

    $this->artisan('import:run', ['dealer' => 'garage-a', 'importer' => 'vehicles', 'file' => $path, '--commit' => true])
        ->assertSuccessful();

    expect(asTenant($this->tenant, fn () => Vehicle::count()))->toBe(3);
});
