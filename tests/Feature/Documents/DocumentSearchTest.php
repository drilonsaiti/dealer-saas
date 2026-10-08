<?php

use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Filament\App\Resources\Documents\Pages\ListDocuments;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Livewire\GlobalSearch;
use Livewire\Livewire;

beforeEach(function () {
    app()->setLocale('en');
    $this->tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a']);
    useAppPanel($this->tenant, makeMember($this->tenant, Role::Sales));

    $this->corolla = StockCycle::factory()->status(StockCycleStatus::Purchased)
        ->for(Vehicle::factory()->state(['stammnummer' => '683737537', 'make' => 'Toyota', 'model' => 'Corolla']))
        ->create();
    $this->golf = StockCycle::factory()->status(StockCycleStatus::Purchased)
        ->for(Vehicle::factory()->state(['stammnummer' => '412118903', 'make' => 'VW', 'model' => 'Golf']))
        ->create();

    $document = function (string $title, string $text, StockCycle $cycle): Document {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $title.$text);
        $document = app(StoreDocument::class)($path, DocumentCategory::where('key', 'purchase_contract')->firstOrFail(), ['title' => $title], [$cycle]);
        $document->currentVersion->forceFill(['ocr_text' => $text, 'ocr_status' => 'done'])->save();

        return $document;
    };

    // The scan only says "Kaufvertrag"; the car comes from the vehicle file it belongs to.
    $this->corollaContract = $document('Ankauf 21.08.2026', 'Kaufvertrag Stammnummer 683.737.537 Preis CHF 15200.00', $this->corolla);
    $this->golfContract = $document('Ankauf 02.09.2026', 'Kaufvertrag Stammnummer 412.118.903', $this->golf);
});

it('finds a document by its text and its vehicle in the documents list', function (string $search) {
    Livewire::test(ListDocuments::class)
        ->searchTable($search)
        ->assertCanSeeTableRecords([$this->corollaContract])
        ->assertCanNotSeeTableRecords([$this->golfContract]);
})->with([
    'text and car' => 'Kaufvertrag Toyota Corolla',
    'beginning of words' => 'Kaufv Coro',
    'Stammnummer as written' => '683.737.537',
    'Stammnummer without dots' => '683737537',
    'price' => '15200',
]);

it('needs every word to match', function () {
    Livewire::test(ListDocuments::class)
        ->searchTable('Kaufvertrag Ferrari')
        ->assertCountTableRecords(0);
});

it('finds documents and vehicles in the global search', function () {
    $results = Livewire::test(GlobalSearch::class)->set('search', 'Kaufvertrag Toyota Corolla')->instance()->getResults();
    $documents = $results?->getCategories()->get(__('Documents'));

    expect($documents)->toHaveCount(1)
        ->and($documents->first())->toBeInstanceOf(GlobalSearchResult::class)
        ->and($documents->first()->title)->toBe('Ankauf 21.08.2026')
        ->and($documents->first()->url)->toContain($this->corolla->getRouteKey());

    $vehicles = Livewire::test(GlobalSearch::class)->set('search', 'Toyota Corolla')->instance()->getResults()?->getCategories()->get(__('Vehicles'));

    expect($vehicles)->toHaveCount(1);
});
