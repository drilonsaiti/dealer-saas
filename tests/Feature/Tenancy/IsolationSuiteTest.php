<?php

use App\Domain\Accounting\Actions\CreateAccountingExport;
use App\Domain\Accounting\Actions\SaveAccountMappings;
use App\Domain\Api\Actions\IssueApiToken;
use App\Domain\Api\Models\WebhookDelivery;
use App\Domain\Api\Models\WebhookEndpoint;
use App\Domain\Audit\MorphMap;
use App\Domain\Checklists\Actions\SyncChecklist;
use App\Domain\Documents\Actions\GenerateContract;
use App\Domain\Documents\Actions\SetRequiredDocumentStatus;
use App\Domain\Documents\Enums\RequiredDocumentStatus;
use App\Domain\Financing\Models\BuybackObligation;
use App\Domain\Financing\Models\Financing;
use App\Domain\Import\Actions\CreateImportRun;
use App\Domain\Import\Actions\RunImport;
use App\Domain\Import\Enums\ImporterType;
use App\Domain\Import\Support\SpreadsheetReader;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\Integrations\Models\IntegrationLog;
use App\Domain\Invoicing\Actions\IssueInvoice;
use App\Domain\Invoicing\Actions\SaveInvoiceDraft;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Listings\Actions\ReceiveEnquiry;
use App\Domain\Listings\Actions\SaveListing;
use App\Domain\Listings\Models\ListingPublication;
use App\Domain\Parties\Models\Party;
use App\Domain\Payments\Actions\RecordPayment;
use App\Domain\Payments\Models\BankTransaction;
use App\Domain\Preparation\Actions\ManageRepairOrder;
use App\Domain\Preparation\Actions\RecordConditionReport;
use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Sales\Enums\SaleItemKind;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Models\TradeIn;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Signatures\Actions\StartSigning;
use App\Domain\Signatures\Enums\SigningMethod;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Vat\Actions\SaveVatProfile;
use App\Domain\Vat\Models\VatCode;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\TyreSet;
use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Models\WarrantyClaim;
use App\Domain\Warranty\Models\WarrantyProduct;
use Filament\Facades\Filament;
use Filament\Livewire\GlobalSearch;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Acceptance test 15: dealer B has one of everything; dealer A sees none of it — not in raw
 * SQL, not through Eloquent, not on any screen of the dealer app and not in global search.
 * New tables with a tenant_id are picked up automatically and must get test data here.
 */

function isolationCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'iso').'.csv';
    $handle = fopen($path, 'wb');

    foreach ($rows as $row) {
        fputcsv($handle, $row, ';', escape: '');
    }

    fclose($handle);

    return $path;
}

function isolationImport(ImporterType $type, string $path, string $name): void
{
    $mapping = $type === ImporterType::Documents
        ? []
        : CreateImportRun::guessMapping(RunImport::importer($type)->guesses(), (new SpreadsheetReader($path, $name))->headers());

    $run = app(CreateImportRun::class)($path, $name, $type, $mapping, [], savePresetAs: $type === ImporterType::Documents ? null : 'Bravo preset');
    app(RunImport::class)->commit(app(RunImport::class)->dryRun($run)->refresh());
}

/** One of everything for a dealer, built through the real actions where they exist. */
function fillDealer(Tenant $tenant, string $marker, string $stammnummer): void
{
    asTenant($tenant, function () use ($marker, $stammnummer) {
        isolationImport(ImporterType::Vehicles, isolationCsv([
            ['Nr.', 'Stammnummer', 'Fahrzeug', 'Status', 'EK Datum', 'EK CHF', 'Verkäufer', 'VK Datum', 'VK CHF', 'Käufer'],
            ['1', $stammnummer, "Ferrari Roma {$marker}", 'Verkauft', '2026-01-10', '150000', "{$marker} Garage AG", '2026-02-01', '180000', "Hans {$marker}"],
            ['2', null, "Fiat Panda {$marker}", 'Bestand', '2026-03-10', '5000', "{$marker} Garage AG", null, null, null],
        ]), "{$marker}-fahrzeuge.csv");

        isolationImport(ImporterType::Costs, isolationCsv([
            ['Kosten-ID', 'Fahrzeug Nr.', 'Datum', 'Betrag CHF', 'Kategorie', 'Beschreibung'],
            ["K-{$marker}", '2', '2026-03-12', '300', 'Transport', "Transport {$marker}"],
        ]), "{$marker}-kosten.csv");

        $zip = tempnam(sys_get_temp_dir(), 'iso').'.zip';
        $archive = new ZipArchive;
        $archive->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $archive->addFromString("2026/{$stammnummer}_{$marker}/01_Ankauf/2026-01-10_{$stammnummer}_Kaufvertrag_D1.pdf", "%PDF-1.4 {$marker}");
        $archive->close();
        isolationImport(ImporterType::Documents, $zip, "{$marker}-ordner.zip");

        $panda = StockCycle::where('legacy_ref', '2')->firstOrFail();
        $sale = Sale::firstOrFail();

        Commitment::factory()->for($panda)->create(['description' => "Service {$marker}"]);
        TyreSet::factory()->create(['vehicle_id' => $panda->vehicle_id]);
        SaleItem::create(['sale_id' => $sale->id, 'kind' => SaleItemKind::Accessory, 'description' => "Matten {$marker}", 'qty' => 1, 'unit_price_rp' => 10000]);
        TradeIn::create(['sale_id' => $sale->id, 'vehicle_data' => ['make' => 'Lada', 'model' => $marker], 'value_rp' => 100000]);
        $bank = BankAccount::factory()->create(['label' => "Konto {$marker}"]);
        app(SetRequiredDocumentStatus::class)($panda, 'coc', RequiredDocumentStatus::Requested, "Note {$marker}");

        // VAT settings: the invoice below creates tax entries and a VAT period.
        app(SaveVatProfile::class)(null, ['valid_from' => '2026-01-01', 'method' => 'net_tax_rate', 'basis' => 'agreed', 'period' => 'half_year'], [['activity' => "Handel {$marker}", 'rate' => '0.6']]);

        // An issued invoice, partly paid, and a bank booking.
        $invoice = app(IssueInvoice::class)(app(SaveInvoiceDraft::class)(null, [
            'type' => InvoiceType::Standard,
            'recipient_party_id' => Party::factory()->create(['last_name' => "Kunde {$marker}"])->id,
            'stock_cycle_id' => $panda->id,
        ], [['description' => "Service {$marker}", 'unit_price_rp' => 50_000, 'vat_code_id' => VatCode::byKey('taxable_normal')->id]]));
        app(RecordPayment::class)(['direction' => 'in', 'paid_on' => '2026-03-15', 'amount_rp' => 10_000, 'method' => 'cash'], [[$invoice, 10_000]]);
        BankTransaction::create(['bank_account_id' => $bank->id, 'entry_key' => hash('sha256', $marker), 'booked_on' => '2026-03-16', 'amount_rp' => 500, 'counterparty' => "Bank {$marker}"]);

        // Leasing with buy-back, a warranty with a claim, and both checklists.
        $financing = Financing::create(['sale_id' => $sale->id, 'partner_party_id' => Party::factory()->create(['last_name' => "Bank {$marker}"])->id, 'applied_on' => '2026-02-01', 'cash_price_rp' => 18_000_000]);
        BuybackObligation::create(['financing_id' => $financing->id, 'vehicle_id' => $sale->stockCycle->vehicle_id, 'amount_rp' => 9_000_000, 'due_on' => '2030-02-01', 'remind_on' => '2029-11-01']);
        $product = WarrantyProduct::create(['name' => ['de' => "Garantie {$marker}"], 'duration_months' => 12]);
        $warranty = Warranty::create(['stock_cycle_id' => $sale->stock_cycle_id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'duration_months' => 12, 'policy_number' => "P-{$marker}"]);
        WarrantyClaim::create(['warranty_id' => $warranty->id, 'occurred_on' => '2026-05-01', 'mileage' => 1000, 'description' => "Schaden {$marker}"]);
        app(SyncChecklist::class)->handover($sale);
        app(SyncChecklist::class)->partner($financing);

        // Preparation: condition report with a damage and a repair order.
        $report = app(RecordConditionReport::class)($panda, ['summary' => "Zustand {$marker}"], [['area' => 'front', 'kind' => 'scratch']]);
        app(ManageRepairOrder::class)->create($panda, ['description' => "Reparatur {$marker}"], [$report->damages->first()->id]);

        // Listing with a website publication, an API token, an enquiry and a webhook with a delivery.
        $listing = app(SaveListing::class)($panda, ['title' => "Panda {$marker}", 'price_rp' => 600_000]);
        ListingPublication::create(['listing_id' => $listing->id, 'channel' => 'website']);
        app(IssueApiToken::class)("Token {$marker}", ['listings:read']);
        app(ReceiveEnquiry::class)(['name' => "Interessent {$marker}", 'email' => strtolower($marker).'@example.ch', 'message' => "Frage {$marker}"], $listing);
        $hook = WebhookEndpoint::create(['url' => 'https://example.ch/'.$marker, 'secret' => 's', 'events' => ['vehicle.sold']]);
        WebhookDelivery::create(['endpoint_id' => $hook->id, 'event' => 'vehicle.sold', 'payload' => ['marker' => $marker]]);

        // A portal account (inactive, so nothing is sent) with a log entry.
        $portal = IntegrationAccount::create(['provider' => 'autoscout24', 'credentials' => ['client_id' => $marker]]);
        IntegrationLog::create(['integration_account_id' => $portal->id, 'listing_id' => $listing->id, 'action' => 'test', 'status' => 'ok', 'message' => "Log {$marker}"]);

        // Accounting: an own account number and an export (with its items and journal file).
        app(SaveAccountMappings::class)(['vehicle_sales' => '3010']);
        app(CreateAccountingExport::class)('2026-12-31');

        // A purchase contract out for signature (templates, signature requests, signers).
        $contract = app(GenerateContract::class)($panda->purchase, 'de', "Vertrag {$marker}");
        app(StartSigning::class)($contract, SigningMethod::OnDevice, [], auth()->user());
    });
}

/**
 * Tables with a tenant_id that are deliberately not under RLS. Memberships are read across
 * dealers at login and by the dealer switcher; screens filter them by the current dealer.
 */
const ISOLATION_RLS_EXCEPTIONS = ['tenant_user'];

/**
 * @return list<string>
 */
function tenantTables(bool $withExceptions = false): array
{
    return collect(DB::select("select table_name from information_schema.columns where table_schema = current_schema() and column_name = 'tenant_id' order by table_name"))
        ->pluck('table_name')
        ->reject(fn (string $table): bool => ! $withExceptions && in_array($table, ISOLATION_RLS_EXCEPTIONS, true))
        ->values()
        ->all();
}

beforeEach(function () {
    app()->setLocale('en');
    fakeGotenberg();
    $this->a = makeDealer(['name' => 'Garage Alpha', 'slug' => 'garage-alpha']);
    $this->b = makeDealer(['name' => 'Garage Bravo', 'slug' => 'garage-bravo']);
    $this->adminA = makeMember($this->a, Role::Administrator);
    $this->adminB = makeMember($this->b, Role::Administrator);

    $this->actingAs($this->adminA);
    fillDealer($this->a, 'Alpha', '683737537');

    $this->actingAs($this->adminB);
    fillDealer($this->b, 'Bravo', '412118903');
});

it('has data of dealer B in every table that belongs to a dealer', function () {
    $empty = tenantContext()->bypass(fn () => collect(tenantTables())
        ->filter(fn (string $table): bool => DB::table($table)->where('tenant_id', $this->b->id)->doesntExist())
        ->values()
        ->all());

    expect($empty)->toBe([]);
});

it('protects every dealer table with forced row-level security', function () {
    $unprotected = collect(DB::select("select relname from pg_class where relkind = 'r' and relnamespace = current_schema()::regnamespace and not (relrowsecurity and relforcerowsecurity)"))
        ->pluck('relname')
        ->intersect(tenantTables())
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});

it('shows dealer A none of dealer B\'s rows in raw SQL', function () {
    asTenant($this->a, function () {
        foreach (tenantTables() as $table) {
            expect(DB::table($table)->where('tenant_id', $this->b->id)->count())->toBe(0, "{$table}: rows of dealer B visible")
                ->and(DB::table($table)->where('tenant_id', '!=', $this->a->id)->count())->toBe(0, "{$table}: rows of another dealer visible")
                ->and(DB::table($table)->count())->toBeGreaterThan(0, "{$table}: dealer A has no rows to compare");
        }
    });
});

it('shows dealer A none of dealer B\'s records through Eloquent, even without the app scope', function () {
    asTenant($this->a, function () {
        foreach (MorphMap::MAP as $alias => $class) {
            /** @var Model $model */
            $model = new $class;

            if (! in_array($model->getTable(), tenantTables(), true)) {
                continue;
            }

            expect($class::query()->where('tenant_id', $this->b->id)->count())->toBe(0, "{$alias} (scoped)")
                ->and($class::query()->withoutGlobalScopes()->where('tenant_id', $this->b->id)->count())->toBe(0, "{$alias} (RLS only)");
        }
    });
});

it('shows dealer A nothing of dealer B on any list in the dealer app', function () {
    useAppPanel($this->a, $this->adminA);

    $resources = collect(Filament::getPanel('app')->getResources());
    expect($resources)->not->toBeEmpty();

    foreach ($resources as $resource) {
        /** @var class-string<resource> $resource */
        $model = $resource::getModel();
        $page = $resource::getPages()['index']->getPage();

        $recordsOfB = tenantContext()->bypass(fn () => $model::query()->withoutGlobalScopes()->where('tenant_id', $this->b->id)->get());
        expect($recordsOfB)->not->toBeEmpty("{$resource}: dealer B has no records to hide");

        $component = Livewire::test($page);

        if (method_exists($component->instance(), 'getTabs') && array_key_exists('all', $component->instance()->getTabs())) {
            $component->set('activeTab', 'all');
        }

        $component->assertCanNotSeeTableRecords($recordsOfB)
            ->assertDontSee('Bravo')
            ->assertDontSee('412.118.903');
    }
});

it('finds nothing of dealer B in global search', function () {
    useAppPanel($this->a, $this->adminA);

    $search = fn (string $term) => Livewire::test(GlobalSearch::class)->set('search', $term)->instance()->getResults();

    // Control: the same search finds dealer A's own data.
    expect($search('Alpha')?->getCategories())->not->toBeEmpty();

    foreach (['Bravo', '412118903', '412.118.903', 'Hans Bravo'] as $term) {
        expect($search($term)?->getCategories() ?? collect())->toBeEmpty("global search for {$term}");
    }
});
