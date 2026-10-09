<?php

use App\Domain\Checklists\Actions\SaveChecklistTemplate;
use App\Domain\Checklists\Actions\SyncChecklist;
use App\Domain\Checklists\Actions\TickChecklistItem;
use App\Domain\Checklists\Enums\ChecklistKind;
use App\Domain\Checklists\Models\ChecklistTemplate;
use App\Domain\Financing\Enums\FinancingStatus;
use App\Domain\Financing\Models\BuybackObligation;
use App\Domain\Financing\Models\Financing;
use App\Domain\Parties\Models\Party;
use App\Domain\Sales\Actions\ContractSale;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Warranty\Actions\ActivateWarranties;
use App\Domain\Warranty\Actions\AddWarranty;
use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Models\WarrantyClaim;
use App\Domain\Warranty\Models\WarrantyProduct;
use App\Filament\App\Resources\BuybackObligations\Pages\ListBuybackObligations;
use App\Filament\App\Resources\ChecklistTemplates\Pages\ManageChecklistTemplates;
use App\Filament\App\Resources\Financings\Pages\ViewFinancing;
use App\Filament\App\Resources\StockCycles\Pages\ViewStockCycle;
use App\Filament\App\Resources\Warranties\Pages\ViewWarranty;
use App\Filament\App\Resources\Warranties\RelationManagers\ClaimsRelationManager;
use App\Filament\App\Resources\WarrantyProducts\Pages\ManageWarrantyProducts;
use App\Support\BusinessRuleException;
use Filament\Forms\Components\Repeater;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    app()->setLocale('en');
    fakeGotenberg();
    Carbon::setTestNow('2026-10-09 10:00');
    $this->tenant = makeDealer(['slug' => 'aziri', 'street' => 'Industriestrasse 5', 'zip' => '3052', 'city' => 'Zollikofen']);
    $this->sales = makeMember($this->tenant, Role::Sales);
    asTenant($this->tenant, fn () => BankAccount::factory()->create(['is_default' => true]));
});

afterEach(fn () => Carbon::setTestNow());

it('leaves leasing and warranty items out of a cash sale and keeps rule items out of manual ticking', function () {
    $this->actingAs($this->sales);

    asTenant($this->tenant, function () {
        $sale = app(ContractSale::class)(reservedSale()->stockCycle, []);
        $checklist = app(SyncChecklist::class)->handover($sale);
        $byKey = $checklist->items->keyBy('key');

        expect($byKey['revocation']->applicable)->toBeFalse()
            ->and($byKey['warranty']->applicable)->toBeFalse()
            ->and($byKey['commitments']->isDone())->toBeTrue() // no promises open
            ->and($byKey['commitments']->auto)->toBeTrue()
            ->and($byKey['paid']->isDone())->toBeFalse()
            ->and($checklist->openRequired()->pluck('key')->all())->toBe(['paid', 'keys'])
            ->and(fn () => app(TickChecklistItem::class)($byKey['paid']))->toThrow(BusinessRuleException::class, 'automatically')
            ->and(app(TickChecklistItem::class)($byKey['contract_signed'])->isDone())->toBeTrue(); // signed on paper
    });
});

it('versions a checklist template; started checklists keep their items', function () {
    $this->actingAs($this->sales);

    asTenant($this->tenant, function () {
        $sale = app(ContractSale::class)(reservedSale()->stockCycle, []);
        $started = app(SyncChecklist::class)->handover($sale);
        $v1 = ChecklistTemplate::query()->where('kind', 'handover')->sole();

        $v2 = app(SaveChecklistTemplate::class)($v1, ['name' => ['de' => 'Übergabe']], [
            ['label' => ['de' => 'Fahrzeug gereinigt'], 'required' => true],
            ['label' => ['de' => 'Bezahlt'], 'required' => true, 'auto_rule' => 'sale.paid'],
        ]);

        expect($v2->version)->toBe(2)
            ->and($v1->refresh()->is_active)->toBeFalse()
            ->and($v2->items->first()->getTranslation('label', 'fr'))->toBe('Fahrzeug gereinigt')
            ->and($started->refresh()->items()->count())->toBe(7)
            ->and(fn () => app(SaveChecklistTemplate::class)($v2, ['name' => 'X'], [['label' => ['de' => 'Y'], 'auto_rule' => 'nonsense']]))
            ->toThrow(BusinessRuleException::class, 'Unknown automatic rule');
    });
});

it('records a leasing, adds a warranty and hands over from the vehicle file', function () {
    useAppPanel($this->tenant, $this->sales);
    $sale = reservedSale(['items' => []]);
    $cycle = $sale->stockCycle;
    $bank = Party::factory()->create(['kind' => 'company', 'company_name' => 'Cembra Money Bank AG']);
    $product = WarrantyProduct::create(['name' => ['de' => 'FoxG3', 'fr' => 'FoxG3', 'it' => 'FoxG3', 'en' => 'FoxG3'], 'duration_months' => 12, 'cost_rp' => 39_000, 'price_rp' => 69_000]);

    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->callAction('financing', data: ['partner_party_id' => $bank->id, 'kind' => 'leasing', 'applied_on' => '2026-10-09', 'cash_price_rp' => '26900', 'collection_rp' => '4500'])
        ->assertHasNoActionErrors()
        ->callAction('addWarranty', data: ['product_id' => $product->id, 'price_rp' => '690', 'cost_rp' => '390'])
        ->assertHasNoActionErrors();

    $financing = Financing::query()->sole();

    expect($financing->payout_expected_rp)->toBe(2_240_000)
        ->and(Warranty::query()->sole()->price_rp)->toBe(69_000);

    Livewire::test(ViewFinancing::class, ['record' => $financing->getRouteKey()])
        ->callAction('approve')
        ->assertHasNoActionErrors()
        ->callAction('contractReceived', data: ['received_on' => '2026-10-09', 'contract_number' => '4032480511', 'term_months' => 49, 'residual_rp' => '12283', 'has_buyback' => true])
        ->assertHasNoActionErrors();

    expect($financing->refresh()->status)->toBe(FinancingStatus::ContractReceived)
        ->and(BuybackObligation::query()->sole()->amount_rp)->toBe(1_228_300);

    app(ContractSale::class)($cycle, []);

    // Not ready: payout and warranty registration are still missing.
    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->callAction('handOver', data: ['on' => '2026-10-09', 'mileage_out' => 80_000])
        ->assertNotified();

    expect($sale->refresh()->status)->toBe(SaleStatus::Contracted);
});

it('handles a claim and the settings screens', function () {
    $admin = makeMember($this->tenant, Role::Administrator);
    useAppPanel($this->tenant, $admin);
    Repeater::fake();

    Livewire::test(ManageWarrantyProducts::class)
        ->callAction('create', data: ['name' => ['de' => 'Eigene Garantie'], 'duration_months' => 6, 'deductible_rp' => '0', 'cost_rp' => '0', 'price_rp' => '0'])
        ->assertHasNoActionErrors();

    expect(WarrantyProduct::query()->sole()->getTranslation('name', 'it'))->toBe('Eigene Garantie');

    Livewire::test(ManageChecklistTemplates::class)
        ->assertCountTableRecords(2)
        ->callAction('create', data: ['kind' => 'financing_partner', 'name' => ['de' => 'Bank Now'], 'items' => [['label' => ['de' => 'Ausweiskopie'], 'required' => true]]])
        ->assertHasNoActionErrors();

    expect(ChecklistTemplate::query()->where('kind', ChecklistKind::FinancingPartner->value)->count())->toBe(2);

    $sale = reservedSale();
    $warranty = app(AddWarranty::class)($sale, WarrantyProduct::query()->sole());
    app(ActivateWarranties::class)($sale, Carbon::parse('2026-10-01'), 80_000);

    Livewire::test(ClaimsRelationManager::class, ['ownerRecord' => $warranty->refresh(), 'pageClass' => ViewWarranty::class])
        ->callTableAction('report', data: ['occurred_on' => '2026-10-05', 'mileage' => 80_500, 'description' => 'Klimaanlage'])
        ->assertHasNoTableActionErrors();

    $claim = WarrantyClaim::query()->sole();

    Livewire::test(ClaimsRelationManager::class, ['ownerRecord' => $warranty, 'pageClass' => ViewWarranty::class])
        ->callTableAction('decide', $claim, data: ['amount_rp' => '500', 'deductible_rp' => '0', 'provider_share_rp' => '0', 'dealer_share_rp' => '500'])
        ->assertHasNoTableActionErrors()
        ->callTableAction('settle', $claim->refresh())
        ->assertHasNoTableActionErrors();

    expect($claim->refresh()->cost->gross_rp)->toBe(50_000);

    Livewire::test(ListBuybackObligations::class)->assertSuccessful();
});

it('lets only sales and admins change a financing', function () {
    $readOnly = makeMember($this->tenant, Role::ReadOnly);

    asTenant($this->tenant, fn () => expect($readOnly->can('create', Financing::class))->toBeFalse()
        ->and($readOnly->can('viewAny', Financing::class))->toBeTrue()
        ->and($this->sales->can('create', Financing::class))->toBeTrue()
        ->and($this->sales->can('update', new BuybackObligation))->toBeTrue()
        ->and($this->sales->can('create', WarrantyProduct::class))->toBeFalse());
});
