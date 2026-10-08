<?php

use App\Domain\Documents\Actions\AddDocumentVersion;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentFileName;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Filament\App\Resources\StockCycles\Pages\ViewStockCycle;
use App\Filament\App\Resources\StockCycles\RelationManagers\DocumentsRelationManager;
use Livewire\Livewire;

/*
 * Downloads are named {folder no}_{folder}_{car}_{type}_{date}_{running no}.{ext}, so a vehicle
 * file with 10 or 100 documents is still readable in the Downloads folder.
 */

beforeEach(function () {
    app()->setLocale('en');
    $this->tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a']);
    $this->user = makeMember($this->tenant, Role::Sales);
    $this->actingAs($this->user);
    tenantContext()->set($this->tenant);

    $this->cycle = StockCycle::factory()->status(StockCycleStatus::Purchased)
        ->for(Vehicle::factory()->state(['make' => 'BMW', 'model' => 'X3']))
        ->create();
});

it('names a download after folder, car, type and date', function () {
    $contract = storeDoc('purchase_contract', 'contract', ['document_on' => '2026-07-14'], [$this->cycle]);

    expect(DocumentFileName::forDownload($contract))->toBe('01_Purchase_BMW-X3_Purchase-contract_2026-07-14_01.pdf');
});

it('numbers documents of the same type in the order they were added', function () {
    $first = storeDoc('workshop_invoice', 'invoice one', ['document_on' => '2026-07-20'], [$this->cycle]);
    $second = storeDoc('workshop_invoice', 'invoice two', ['document_on' => '2026-07-01'], [$this->cycle]);
    $third = storeDoc('workshop_invoice', 'invoice three', [], [$this->cycle]);

    expect(DocumentFileName::forDownload($first))->toEndWith('_Workshop-invoice_2026-07-20_01.pdf')
        ->and(DocumentFileName::forDownload($second))->toEndWith('_Workshop-invoice_2026-07-01_02.pdf')
        ->and(DocumentFileName::forDownload($third))->toEndWith('_Workshop-invoice_UNDATIERT_03.pdf');

    // A later document never renames the earlier ones.
    storeDoc('workshop_invoice', 'invoice four', [], [$this->cycle]);

    expect(DocumentFileName::forDownload($first->refresh()))->toEndWith('_2026-07-20_01.pdf');
});

it('starts counting again in every vehicle file', function () {
    $other = StockCycle::factory()->status(StockCycleStatus::Purchased)->for(Vehicle::factory()->state(['make' => 'Audi', 'model' => 'A4']))->create();

    storeDoc('workshop_invoice', 'one', [], [$this->cycle]);
    $document = storeDoc('workshop_invoice', 'two', [], [$other]);

    expect(DocumentFileName::forDownload($document))->toStartWith('03_Costs-workshop_Audi-A4_Workshop-invoice_')->toEndWith('_01.pdf');
});

it('leaves the car out for documents that belong to no vehicle file', function () {
    $document = storeDoc('invoice', 'loose', ['document_on' => '2026-08-01']);

    expect(DocumentFileName::forDownload($document))->toBe('04_Sale-payments_Invoice_2026-08-01_01.pdf');
});

it('writes names in plain ASCII, with German umlauts spelled out', function () {
    app()->setLocale('de');
    $document = storeDoc('handover_protocol', 'protocol', ['document_on' => '2026-08-01'], [$this->cycle]);

    expect(DocumentFileName::forDownload($document))->toMatch('/^[A-Za-z0-9._-]+$/')->toContain('Uebergabeprotokoll');
});

it('marks older versions of a document', function () {
    $document = storeDoc('purchase_contract', 'v1', ['document_on' => '2026-07-14'], [$this->cycle]);
    $old = $document->currentVersion;
    [$path] = explode('|', fakeFile('v2'));
    app(AddDocumentVersion::class)($document, $path, 'neu.pdf');

    expect(DocumentFileName::forDownload($document->refresh(), $old))->toEndWith('_01_v1.pdf')
        ->and(DocumentFileName::forDownload($document))->toEndWith('_01.pdf');
});

it('downloads the document under that name', function () {
    useAppPanel($this->tenant, $this->user);
    $document = storeDoc('purchase_contract', 'contract', ['document_on' => '2026-07-14'], [$this->cycle]);

    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $this->cycle, 'pageClass' => ViewStockCycle::class])
        ->callTableAction('download', $document)
        ->assertFileDownloaded('01_Purchase_BMW-X3_Purchase-contract_2026-07-14_01.pdf');
});
