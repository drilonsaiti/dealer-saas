<?php

use App\Domain\Documents\Actions\AddDocumentVersion;
use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Actions\ExportVehicleFile;
use App\Domain\Documents\Actions\MergeDocuments;
use App\Domain\Documents\Actions\RequiredDocumentsChecklist;
use App\Domain\Documents\Actions\SetRequiredDocumentStatus;
use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Enums\OcrStatus;
use App\Domain\Documents\Enums\RequiredDocumentStatus;
use App\Domain\Documents\Jobs\RunOcr;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Documents\Support\DuplicateDocument;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    app()->setLocale('en');
    $this->tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a', 'default_locale' => 'de']);
    $this->user = makeMember($this->tenant, Role::Sales);
    $this->actingAs($this->user);
});

function fakeFile(string $content, string $name = 'scan.pdf'): string
{
    $path = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($path, $content);

    return $path.'|'.$name;
}

function storeDoc(string $categoryKey, string $content, array $attributes = [], array $links = []): Document
{
    [$path, $name] = explode('|', fakeFile($content, $attributes['original_name'] ?? 'scan.pdf'));
    $category = DocumentCategory::where('key', $categoryKey)->firstOrFail();

    return app(StoreDocument::class)($path, $category, ['original_name' => $name, ...$attributes], $links);
}

it('stores a document under the dealer prefix and links it to the vehicle file', function () {
    Bus::fake([RunOcr::class]);

    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::Purchased)->create();
        $document = storeDoc('purchase_contract', '%PDF-1.4 contract', ['document_on' => '2026-07-14'], [$cycle]);
        $version = $document->currentVersion;

        expect($document->title)->toBe('Purchase contract 14.07.2026')
            ->and($version->version_no)->toBe(1)
            ->and($version->path)->toStartWith("tenants/{$this->tenant->id}/documents/{$document->id}/v1-")
            ->and($version->sha256)->toBe(hash('sha256', '%PDF-1.4 contract'))
            ->and($version->retain_until->toDateString())->toBe('2036-07-14')
            ->and($cycle->documents()->pluck('documents.id')->all())->toBe([$document->id]);

        Storage::disk('local')->assertExists($version->path);
    });

    Bus::assertDispatched(RunOcr::class);
});

it('refuses the exact same file twice', function () {
    asTenant($this->tenant, function () {
        $first = storeDoc('purchase_contract', 'same bytes');

        try {
            storeDoc('invoice', 'same bytes');
            $this->fail('Duplicate was accepted');
        } catch (DuplicateDocument $e) {
            expect($e->existing->id)->toBe($first->id);
        }

        expect(Document::count())->toBe(1)
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    });
});

it('flags a near duplicate and merges it as a version', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::Sold)->create();
        $a = storeDoc('sales_contract', 'scan one', ['document_on' => '2026-07-14'], [$cycle]);
        $b = storeDoc('sales_contract', 'scan two', ['document_on' => '2026-07-14'], [$cycle]);
        $c = storeDoc('sales_contract', 'scan three', ['document_on' => '2026-07-15'], [$cycle]);

        expect($b->possible_duplicate_of_id)->toBe($a->id)
            ->and($c->possible_duplicate_of_id)->toBeNull();

        app(MergeDocuments::class)($a, $b);

        expect(Document::find($b->id))->toBeNull()
            ->and($a->versions()->pluck('version_no')->all())->toBe([2, 1])
            ->and($a->refresh()->current_version_id)->toBe($a->versions()->where('version_no', 1)->value('id'));
    });
});

it('replaces a file with a new version and keeps the old one', function () {
    asTenant($this->tenant, function () {
        $document = storeDoc('registration', 'old scan');
        [$path] = explode('|', fakeFile('better scan'));

        $version = app(AddDocumentVersion::class)($document, $path, 'fahrzeugausweis.pdf');

        expect($version->version_no)->toBe(2)
            ->and($document->refresh()->current_version_id)->toBe($version->id)
            ->and(DocumentVersion::count())->toBe(2);
    });
});

it('locks documents of an archived vehicle file', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::Archived)->create();
        $document = storeDoc('invoice', 'invoice', links: [$cycle]);

        expect($document->isLocked())->toBeTrue()
            ->and(fn () => app(DeleteDocument::class)($document))->toThrow(BusinessRuleException::class, 'locked');
    });
});

it('deletes a wrong upload with its files', function () {
    asTenant($this->tenant, function () {
        $document = storeDoc('vehicle_other', 'oops');
        $path = $document->currentVersion->path;

        app(DeleteDocument::class)($document);

        expect(Document::count())->toBe(0);
        Storage::disk('local')->assertMissing($path);
    });
});

it('hides sensitive documents from roles without permission', function () {
    asTenant($this->tenant, function () {
        $id = storeDoc('seller_identity', 'passport');
        $contract = storeDoc('purchase_contract', 'contract');
        $accounting = makeMember($this->tenant, Role::Accounting);

        expect($this->user->can('view', $id))->toBeFalse()
            ->and($this->user->can('view', $contract))->toBeTrue()
            ->and($accounting->can('view', $id))->toBeTrue()
            ->and(Document::query()->visibleTo(false)->pluck('id')->all())->toBe([$contract->id]);
    });
});

it('needs a photo to list a vehicle', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::ReadyForSale)->create(['list_price_rp' => 1_890_000]);

        expect(fn () => app(TransitionStockCycle::class)($cycle, StockCycleStatus::Listed))
            ->toThrow(BusinessRuleException::class, 'photo');

        attachPhoto($cycle);
        app(TransitionStockCycle::class)($cycle, StockCycleStatus::Listed);

        expect($cycle->refresh()->status)->toBe(StockCycleStatus::Listed);
    });
});

it('reads text from PDFs and scans in the queue', function () {
    Process::fake([
        '*pdfinfo*' => Process::result("Pages:          1\n"),
        '*pdftotext*' => Process::result(str_repeat('Kaufvertrag Toyota Corolla 683.737.537 ', 3)),
        '*--list-langs*' => Process::result("List of available languages (2):\neng\ndeu\n"),
    ]);

    asTenant($this->tenant, function () {
        $document = storeDoc('purchase_contract', '%PDF-1.4 text pdf');
        $version = $document->currentVersion;

        expect($version->refresh()->ocr_status)->toBe(OcrStatus::Done)
            ->and($version->page_count)->toBe(1)
            ->and($version->ocr_text)->toContain('683.737.537');

        $found = DocumentVersion::query()->whereRaw("search_vector @@ plainto_tsquery('simple', ?)", ['corolla'])->pluck('id')->all();
        expect($found)->toBe([$version->id]);
    });
});

it('really recognises text in an image when Tesseract is installed', function () {
    if (! is_executable('/usr/bin/tesseract')) {
        $this->markTestSkipped('Tesseract is not installed.');
    }

    asTenant($this->tenant, function () {
        $image = imagecreatetruecolor(600, 120);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagestring($image, 5, 20, 50, 'KAUFVERTRAG TOYOTA COROLLA', imagecolorallocate($image, 0, 0, 0));
        $path = tempnam(sys_get_temp_dir(), 'scan').'.png';
        imagepng(imagescale($image, 1800), $path);

        $category = DocumentCategory::where('key', 'purchase_contract')->firstOrFail();
        $document = app(StoreDocument::class)($path, $category, ['original_name' => 'scan.png']);

        expect(strtoupper((string) $document->currentVersion->refresh()->ocr_text))->toContain('COROLLA');
    });
});

it('tracks the required documents of a vehicle file', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::Purchased)->create();
        Purchase::factory()->create(['stock_cycle_id' => $cycle->id, 'seller_kind' => 'company']);
        $checklist = app(RequiredDocumentsChecklist::class);

        expect(collect($checklist($cycle))->pluck('key')->all())->toBe(['purchase_contract', 'registration', 'supplier_invoice'])
            ->and($checklist->missingCount($cycle))->toBe(3);

        storeDoc('purchase_contract', 'contract', links: [$cycle]);
        app(SetRequiredDocumentStatus::class)($cycle, 'supplier_invoice', RequiredDocumentStatus::Requested, 'asked Reflex on 14.07.');

        $items = collect($checklist($cycle))->keyBy('key');

        expect($items['purchase_contract']['status'])->toBe(RequiredDocumentStatus::Present)
            ->and($items['supplier_invoice']['status'])->toBe(RequiredDocumentStatus::Requested)
            ->and($items['supplier_invoice']['note'])->toBe('asked Reflex on 14.07.')
            ->and($items['registration']['status'])->toBe(RequiredDocumentStatus::Missing);
    });
});

/*
 * Acceptance test 12: export a full vehicle file (ZIP + PDF overview).
 */
it('exports the whole vehicle file as ZIP with folders and a PDF overview', function () {
    config(['dealer.gotenberg_url' => 'http://gotenberg:3000']);
    Http::fake(['gotenberg:3000/*' => Http::response('%PDF-1.7 overview', 200)]);

    $zipPath = asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::Sold)
            ->for(Vehicle::factory()->state(['stammnummer' => '683737537']))
            ->create();
        Purchase::factory()->create(['stock_cycle_id' => $cycle->id]);
        $contract = storeDoc('sales_contract', 'contract v1', ['document_on' => '2026-07-14', 'locale' => 'de'], [$cycle]);
        [$path] = explode('|', fakeFile('contract v2 signed'));
        app(AddDocumentVersion::class)($contract, $path, 'kaufvertrag.pdf');
        storeDoc('photo', 'photo', ['original_name' => 'front.jpg'], [$cycle]);
        storeDoc('seller_identity', 'id copy', [], [$cycle]); // sensitive: left out for sales

        return app(ExportVehicleFile::class)($cycle, includeSensitive: false);
    });

    $zip = new ZipArchive;
    $zip->open($zipPath);
    $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $i) => $zip->getNameIndex($i))->all();

    expect($names)->toContain('01_Ankauf/')
        ->toContain('06_Leasing_Finanzierung/')
        ->toContain('04_Verkauf_Zahlungen/683737537_2026-07-14_Kaufvertrag-Verkauf_DE_Definitiv.pdf')
        ->toContain('04_Verkauf_Zahlungen/683737537_2026-07-14_Kaufvertrag-Verkauf_DE_Definitiv_v1.pdf')
        ->toContain('02_Fahrzeugunterlagen/683737537_UNDATIERT_Foto_Definitiv.jpg')
        ->toContain('683737537_Übersicht.pdf')
        ->and(collect($names)->filter(fn ($n) => str_contains($n, 'Ausweis')))->toBeEmpty()
        ->and($zip->getFromName('683737537_Übersicht.pdf'))->toBe('%PDF-1.7 overview');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/forms/chromium/convert/html')
        && str_contains($request->body(), '683.737.537'));

    $zip->close();
    unlink($zipPath);
});
