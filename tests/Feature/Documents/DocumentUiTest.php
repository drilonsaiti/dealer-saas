<?php

use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Enums\OcrStatus;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\Documents\Pages\ListDocuments;
use App\Filament\App\Resources\StockCycles\Pages\ViewStockCycle;
use App\Filament\App\Resources\StockCycles\RelationManagers\DocumentsRelationManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    app()->setLocale('en');
    $this->tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a']);
    $this->user = makeMember($this->tenant, Role::Sales);
});

it('uploads several files into the vehicle file and skips exact duplicates', function () {
    useAppPanel($this->tenant, $this->user);

    $cycle = StockCycle::factory()->status(StockCycleStatus::Purchased)->create();
    $category = DocumentCategory::where('key', 'workshop_invoice')->value('id');

    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $cycle, 'pageClass' => ViewStockCycle::class])
        ->callTableAction('upload', data: [
            'files' => [
                UploadedFile::fake()->createWithContent('rechnung-1.pdf', '%PDF invoice one'),
                UploadedFile::fake()->createWithContent('rechnung-2.pdf', '%PDF invoice two'),
                UploadedFile::fake()->createWithContent('rechnung-1-kopie.pdf', '%PDF invoice one'),
            ],
            'category_id' => $category,
            'document_on' => '2026-07-14',
        ])
        ->assertHasNoTableActionErrors();

    expect($cycle->documents()->count())->toBe(2)
        ->and(Document::query()->pluck('title')->unique()->all())->toBe(['Workshop invoice 14.07.2026']);
});

it('finds documents by their recognised text', function () {
    useAppPanel($this->tenant, $this->user);

    $category = DocumentCategory::where('key', 'invoice')->firstOrFail();
    $path = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($path, 'plain');
    $match = app(StoreDocument::class)($path, $category, ['title' => 'Rechnung A']);
    $match->currentVersion->forceFill(['ocr_text' => 'Rechnung an Cembra Money Bank AG', 'ocr_status' => 'done'])->save();
    file_put_contents($path, 'other');
    $other = app(StoreDocument::class)($path, $category, ['title' => 'Rechnung B']);

    Livewire::test(ListDocuments::class)
        ->searchTable('cembra')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('exports the vehicle file from its page', function () {
    useAppPanel($this->tenant, $this->user);

    $cycle = StockCycle::factory()->status(StockCycleStatus::Purchased)->create();
    attachPhoto($cycle);

    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->callAction('export')
        ->assertFileDownloaded();
});

it('reads the text of a waiting document right away, without a queue worker', function () {
    Queue::fake(); // as with a queue nobody works: the upload stays "OCR pending"
    Process::fake([
        '*pdfinfo*' => Process::result("Pages:          1\n"),
        '*pdftotext*' => Process::result(str_repeat('Kaufvertrag Toyota Corolla 683.737.537 ', 3)),
    ]);
    useAppPanel($this->tenant, $this->user);

    $cycle = StockCycle::factory()->status(StockCycleStatus::Purchased)->create();
    $path = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($path, '%PDF-1.4 text pdf');
    $document = app(StoreDocument::class)($path, DocumentCategory::where('key', 'purchase_contract')->firstOrFail(), ['original_name' => 'kv.pdf'], [$cycle]);

    expect($document->currentVersion->ocr_status)->toBe(OcrStatus::Pending);

    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $cycle, 'pageClass' => ViewStockCycle::class])
        ->assertTableActionVisible('recognizeText', $document)
        ->callTableAction('recognizeText', $document)

        ->assertNotified('The text was read; the document is now searchable.');

    expect($document->currentVersion->refresh()->ocr_status)->toBe(OcrStatus::Done)
        ->and($document->currentVersion->ocr_text)->toContain('Corolla');

    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $cycle, 'pageClass' => ViewStockCycle::class])
        ->assertTableActionHidden('recognizeText', $document);
});

it('reads all waiting documents of every dealer from the command line', function () {
    Queue::fake();
    Process::fake([
        '*pdfinfo*' => Process::result("Pages:          1\n"),
        '*pdftotext*' => Process::result(str_repeat('Rechnung Garage ', 5)),
    ]);

    $other = makeDealer(['name' => 'Garage B', 'slug' => 'garage-b']);

    foreach ([$this->tenant, $other] as $tenant) {
        asTenant($tenant, function () {
            $path = tempnam(sys_get_temp_dir(), 'doc');
            file_put_contents($path, '%PDF-1.4 '.uniqid());
            app(StoreDocument::class)($path, DocumentCategory::where('key', 'invoice')->firstOrFail(), ['original_name' => 'r.pdf']);
        });
    }

    $this->artisan('documents:ocr')->assertSuccessful();

    foreach ([$this->tenant, $other] as $tenant) {
        expect(asTenant($tenant, fn () => DocumentVersion::query()->where('ocr_status', OcrStatus::Done)->count()))->toBe(1);
    }
});
