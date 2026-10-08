<?php

use App\Domain\Documents\Actions\ActivateTemplate;
use App\Domain\Documents\Actions\GenerateContract;
use App\Domain\Documents\Actions\SaveTemplateDraft;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\OcrStatus;
use App\Domain\Documents\Enums\TemplateStatus;
use App\Domain\Documents\Enums\TemplateType;
use App\Domain\Documents\Generation\DocumentRenderer;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Parties\Models\Party;
use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Sales\Actions\CancelSale;
use App\Domain\Settings\Models\NumberSequence;
use App\Domain\Tenancy\Enums\Role;
use App\Filament\App\Resources\DocumentTemplates\Pages\ManageDocumentTemplates;
use App\Filament\App\Resources\StockCycles\Pages\ViewStockCycle;
use App\Support\BusinessRuleException;
use Livewire\Livewire;

/*
 * Acceptance test 3 (sales contract in DE, FR, IT, EN) and the contract wizard: data
 * entered once, frozen in a snapshot, numbered on finalisation, versions never overwritten.
 */

beforeEach(function () {
    app()->setLocale('en');
    fakeGotenberg();

    $this->tenant = makeDealer(['name' => 'Aziri Automobile GmbH', 'slug' => 'aziri', 'street' => 'Industriestrasse 5', 'zip' => '3052', 'city' => 'Zollikofen']);
    $this->user = makeMember($this->tenant, Role::Sales);
    $this->actingAs($this->user);
});

it('makes the sales contract in German, French, Italian and English', function (string $locale, string $title, string $clause) {
    asTenant($this->tenant, function () use ($locale, $title, $clause) {
        $html = app(GenerateContract::class)->preview(reservedSale(), $locale, null);

        expect($html)->toContain($title)
            ->toContain($clause)
            ->toContain('683.737.537')
            ->toContain('WBAXX110X0L123456')
            ->toContain('Anna Muster')
            ->toContain('CHF 27’700.00'); // 26’900 + 800 winter wheels
    });
})->with([
    'German' => ['de', 'Kaufvertrag', 'Es gilt schweizerisches Recht.'],
    'French' => ['fr', 'Contrat de vente', 'Le droit suisse est applicable.'],
    'Italian' => ['it', 'Contratto di vendita', 'Si applica il diritto svizzero.'],
    'English' => ['en', 'Sales contract', 'Swiss law applies.'],
]);

it('finalises a numbered, locked contract with its data snapshot in the vehicle file', function () {
    asTenant($this->tenant, function () {
        $sale = reservedSale();
        Commitment::factory()->for($sale->stockCycle)->create(['description' => '4 neue Sommerreifen']);

        $document = app(GenerateContract::class)($sale, 'de', 'Fahrzeug frisch ab MFK.');
        $version = $document->currentVersion;

        expect($document->number)->toBe('KV-00001')
            ->and($document->type_key)->toBe('sales_contract')
            ->and($document->status)->toBe(DocumentStatus::Final)
            ->and($document->locale)->toBe('de')
            ->and($document->category->key)->toBe('sales_contract')
            ->and($version->locked_at)->not->toBeNull()
            ->and($version->ocr_status)->toBe(OcrStatus::NotNeeded)
            ->and($version->sha256)->toBe(hash('sha256', $version->contents()))
            ->and($version->original_name)->toBe('683737537_'.now()->format('Y-m-d').'_Kaufvertrag_DE.pdf')
            ->and($version->template_version_id)->toBe(DocumentTemplate::query()->active()->where('type_key', 'sales_contract')->value('id'))
            ->and($version->data_snapshot['number'])->toBe('KV-00001')
            ->and($version->data_snapshot['buyer']['name'])->toBe('Anna Muster')
            ->and($version->data_snapshot['remarks'])->toBe('Fahrzeug frisch ab MFK.')
            ->and($version->data_snapshot['commitments'][0]['description'])->toBe('4 neue Sommerreifen')
            ->and(Document::query()->linkedTo($sale->stockCycle)->whereKey($document->id)->exists())->toBeTrue()
            ->and(Document::query()->linkedTo($sale)->whereKey($document->id)->exists())->toBeTrue()
            ->and(Document::query()->linkedTo($sale->buyer)->whereKey($document->id)->exists())->toBeTrue();
    });
});

it('never changes a finalised contract when the car or customer changes later', function () {
    asTenant($this->tenant, function () {
        $sale = reservedSale();
        $document = app(GenerateContract::class)($sale, 'de', null);
        $snapshot = $document->currentVersion->data_snapshot;
        $html = app(DocumentRenderer::class)->html($snapshot);

        $sale->buyer->update(['last_name' => 'Anders']);
        $sale->stockCycle->vehicle->update(['vin' => 'WBA00000000000000']);

        $version = $document->refresh()->currentVersion->refresh();

        expect($version->data_snapshot)->toBe($snapshot)
            ->and($version->data_snapshot['buyer']['name'])->toBe('Anna Muster')
            // The same snapshot always gives the same document (reproducible).
            ->and(app(DocumentRenderer::class)->html($version->data_snapshot))->toBe($html);
    });
});

it('keeps the number and adds a version when finalised again after a change', function () {
    asTenant($this->tenant, function () {
        $sale = reservedSale();
        $generate = app(GenerateContract::class);

        $first = $generate($sale, 'de', null);
        $same = $generate($sale, 'de', null);

        expect($same->id)->toBe($first->id)
            ->and($same->versions()->count())->toBe(1);

        $changed = $generate($sale, 'de', 'Mit Anhängerkupplung.');

        expect($changed->id)->toBe($first->id)
            ->and($changed->number)->toBe('KV-00001')
            ->and($changed->versions()->count())->toBe(2)
            ->and($changed->currentVersion->version_no)->toBe(2)
            ->and($changed->versions()->whereNull('locked_at')->count())->toBe(0)
            ->and(NumberSequence::query()->where('key', 'contract')->value('next_value'))->toBe(2);
    });
});

it('refuses to change a signed contract', function () {
    asTenant($this->tenant, function () {
        $sale = reservedSale();
        $document = app(GenerateContract::class)($sale, 'de', null);
        $document->forceFill(['status' => DocumentStatus::Signed])->save();

        expect(fn () => app(GenerateContract::class)($sale, 'de', 'Neu'))
            ->toThrow(BusinessRuleException::class, 'This contract is already signed and cannot be changed.');
    });
});

it('makes no sales contract for a cancelled sale', function () {
    asTenant($this->tenant, function () {
        $sale = reservedSale();
        app(CancelSale::class)($sale, 'Kunde hat abgesagt');

        expect(fn () => app(GenerateContract::class)($sale->refresh(), 'de', null))
            ->toThrow(BusinessRuleException::class);
    });
});

it('makes the purchase contract with the seller and the payoff', function () {
    asTenant($this->tenant, function () {
        $purchase = Purchase::factory()->create([
            'seller_party_id' => Party::factory()->company('Reflex Automobiles Sàrl')->create()->id,
            'price_rp' => 1_520_000,
            'payoff_rp' => 900_000,
            'known_defects' => 'Kratzer hinten links',
        ]);

        $html = app(GenerateContract::class)->preview($purchase, 'fr', null);
        $document = app(GenerateContract::class)($purchase, 'fr', null);

        expect($html)->toContain('Contrat d’achat')
            ->toContain('Reflex Automobiles Sàrl')
            ->toContain('Kratzer hinten links')
            ->toContain('CHF 6’200.00') // paid to the seller after the payoff
            ->and($document->category->key)->toBe('purchase_contract')
            ->and($document->currentVersion->original_name)->toEndWith('_Contrat-d’achat_FR.pdf');
    });
});

it('uses a new template version only after it is activated, and old contracts keep theirs', function () {
    asTenant($this->tenant, function () {
        $sale = reservedSale();
        $old = app(GenerateContract::class)($sale, 'de', null);
        $v1 = DocumentTemplate::query()->active()->where('type_key', 'sales_contract')->firstOrFail();

        $clauses = $v1->clauses;
        $clauses['de'][] = 'Neue Klausel 10.';
        $draft = app(SaveTemplateDraft::class)(TemplateType::SalesContract, ['de' => $clauses['de']], null, 'Klausel 10');

        expect($draft->version)->toBe(2)
            ->and($draft->status)->toBe(TemplateStatus::Draft)
            ->and(fn () => app(ActivateTemplate::class)($draft))->toThrow(BusinessRuleException::class, 'missing in');

        app(SaveTemplateDraft::class)(TemplateType::SalesContract, $clauses, null, 'Klausel 10', $draft);
        app(ActivateTemplate::class)($draft->refresh());

        $new = app(GenerateContract::class)($sale, 'de', 'Version 2');

        expect($v1->refresh()->status)->toBe(TemplateStatus::Retired)
            ->and($new->currentVersion->template_version_id)->toBe($draft->id)
            ->and($new->currentVersion->data_snapshot['clauses'])->toContain('Neue Klausel 10.')
            ->and($old->versions()->where('version_no', 1)->value('template_version_id'))->toBe($v1->id);
    });
});

it('runs the contract wizard from the vehicle file', function () {
    useAppPanel($this->tenant, $this->user);
    $sale = reservedSale();

    Livewire::test(ViewStockCycle::class, ['record' => $sale->stockCycle->getRouteKey()])
        ->assertActionVisible('salesContract')
        ->callAction('salesContract', data: ['locale' => 'it', 'remarks' => 'Consegna con 2 chiavi.'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $document = Document::query()->where('type_key', 'sales_contract')->firstOrFail();

    expect($document->locale)->toBe('it')
        ->and($document->currentVersion->data_snapshot['remarks'])->toBe('Consegna con 2 chiavi.');
});

it('lets administrators manage templates, nobody else', function () {
    $admin = makeMember($this->tenant, Role::Administrator);

    useAppPanel($this->tenant, $this->user);
    Livewire::test(ManageDocumentTemplates::class)->assertForbidden();

    useAppPanel($this->tenant, $admin);
    Livewire::test(ManageDocumentTemplates::class)
        ->assertCanSeeTableRecords(DocumentTemplate::query()->get())
        ->callAction('newVersion', data: [
            'type_key' => 'purchase_contract',
            'clauses' => ['de' => [['text' => 'A']], 'fr' => [['text' => 'B']], 'it' => [['text' => 'C']], 'en' => [['text' => 'D']]],
            'notes' => 'Kürzer',
        ])
        ->assertHasNoActionErrors();

    $draft = DocumentTemplate::query()->where('type_key', 'purchase_contract')->where('version', 2)->firstOrFail();

    expect($draft->status)->toBe(TemplateStatus::Draft)
        ->and($draft->clauses)->toEqual(['de' => ['A'], 'fr' => ['B'], 'it' => ['C'], 'en' => ['D']]);
});
