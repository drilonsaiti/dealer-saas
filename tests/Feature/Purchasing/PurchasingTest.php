<?php

use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Parties\Models\Party;
use App\Domain\Purchasing\Actions\CompleteCommitment;
use App\Domain\Purchasing\Actions\ConfirmCost;
use App\Domain\Purchasing\Actions\InstallDefaultCostCategories;
use App\Domain\Purchasing\Actions\RecordCost;
use App\Domain\Purchasing\Actions\RecordPurchase;
use App\Domain\Purchasing\Actions\SplitCost;
use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\StockCycles\Pages\ViewStockCycle;
use App\Filament\App\Resources\StockCycles\RelationManagers\CostsRelationManager;
use App\Support\BusinessRuleException;
use Livewire\Livewire;

beforeEach(function () {
    app()->setLocale('en');
    $this->tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a']);
    $this->user = makeMember($this->tenant, Role::Sales);
    $this->actingAs($this->user);
});

function categoryId(string $key): string
{
    return (string) CostCategory::query()->where('key', $key)->value('id');
}

it('turns a file in review into a purchased one with number and file year', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->create(['mileage_in' => null]);
        $seller = Party::factory()->create(['roles' => [PartyRole::Customer]]);

        app(RecordPurchase::class)($cycle, [
            'seller_party_id' => $seller->id,
            'seller_kind' => 'private',
            'contract_on' => '2025-12-18',
            'price_rp' => 1_520_000,
            'mileage' => 79310,
        ]);

        $cycle->refresh();

        expect($cycle->status)->toBe(StockCycleStatus::Purchased)
            ->and($cycle->number)->not->toBeNull()
            ->and($cycle->file_year)->toBe(2025)
            ->and($cycle->purchased_on->toDateString())->toBe('2025-12-18')
            ->and($cycle->mileage_in)->toBe(79310)
            ->and($seller->refresh()->hasRole(PartyRole::PrivateSeller))->toBeTrue();
    });
});

it('corrects a purchase without issuing a second number', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->create();
        $data = ['seller_party_id' => Party::factory()->create()->id, 'seller_kind' => 'private', 'contract_on' => now()->toDateString(), 'price_rp' => 1_000_000];

        app(RecordPurchase::class)($cycle, $data);
        $number = $cycle->refresh()->number;

        app(RecordPurchase::class)($cycle, [...$data, 'price_rp' => 1_050_000]);

        expect($cycle->refresh()->number)->toBe($number)
            ->and($cycle->purchase->price_rp)->toBe(1_050_000);
    });
});

it('needs seller, price and date', function () {
    asTenant($this->tenant, function () {
        expect(fn () => app(RecordPurchase::class)(StockCycle::factory()->create(), ['seller_kind' => 'private']))
            ->toThrow(BusinessRuleException::class, 'Choose the seller');
    });
});

it('gives every new dealer the default cost categories once', function () {
    $tenant = app(CreateTenant::class)(['name' => 'Garage Neu'], 'chef@garage-neu.example.ch', sendInvitation: false);

    asTenant($tenant, function () {
        expect(CostCategory::count())->toBe(count(InstallDefaultCostCategories::DEFAULTS))
            ->and(app(InstallDefaultCostCategories::class)())->toBe(0);

        app()->setLocale('fr');
        expect(CostCategory::where('key', 'repair')->sole()->name)->toBe('Réparation / atelier');
    });
});

it('locks confirmed costs', function () {
    asTenant($this->tenant, function () {
        $cost = app(RecordCost::class)(['stock_cycle_id' => StockCycle::factory()->create()->id, 'category_id' => categoryId('transport'), 'incurred_on' => now()->toDateString(), 'gross_rp' => 35_000, 'is_estimate' => true]);

        app(ConfirmCost::class)($cost);

        expect($cost->refresh()->isConfirmed())->toBeTrue()
            ->and($cost->is_estimate)->toBeFalse()
            ->and($this->user->can('update', $cost))->toBeFalse()
            ->and(fn () => app(RecordCost::class)(['gross_rp' => 1], $cost))->toThrow(BusinessRuleException::class, 'confirmed');
    });
});

it('refuses costs on closed vehicle files', function () {
    asTenant($this->tenant, function () {
        $archived = StockCycle::factory()->status(StockCycleStatus::Archived)->create();

        expect(fn () => app(RecordCost::class)(['stock_cycle_id' => $archived->id, 'category_id' => categoryId('repair'), 'incurred_on' => now()->toDateString(), 'gross_rp' => 1000]))
            ->toThrow(BusinessRuleException::class, 'closed');
    });
});

it('records general operating costs without a vehicle', function () {
    asTenant($this->tenant, function () {
        $cost = app(RecordCost::class)(['category_id' => categoryId('other'), 'incurred_on' => now()->toDateString(), 'description' => 'Showroom cleaning', 'gross_rp' => 25_000]);

        expect($cost->stock_cycle_id)->toBeNull();
    });
});

it('splits one invoice across several cars to the Rappen', function () {
    expect(SplitCost::parts(10_000, 3))->toBe([3_334, 3_333, 3_333]);

    asTenant($this->tenant, function () {
        $cycles = StockCycle::factory()->count(3)->status(StockCycleStatus::Arrived)->create();

        $costs = app(SplitCost::class)(
            ['category_id' => categoryId('transport'), 'incurred_on' => now()->toDateString(), 'description' => 'Transport x3', 'gross_rp' => 100_000, 'vat_rp' => 7_493],
            $cycles->pluck('id')->all(),
        );

        expect($costs)->toHaveCount(3)
            ->and($costs->sum('gross_rp'))->toBe(100_000)
            ->and($costs->sum('vat_rp'))->toBe(7_493)
            ->and($costs->pluck('split_group_id')->unique())->toHaveCount(1);
    });
});

it('blocks the handover until every promise is kept', function () {
    asTenant($this->tenant, function () {
        $cycle = StockCycle::factory()->status(StockCycleStatus::Sold)->create(['mileage_in' => 10_000]);
        $promise = Commitment::factory()->create(['stock_cycle_id' => $cycle->id]);

        expect(fn () => app(TransitionStockCycle::class)($cycle, StockCycleStatus::Delivered, data: ['mileage_out' => 10_100]))
            ->toThrow(BusinessRuleException::class, 'Promises to the customer still open: 1');

        $cost = app(RecordCost::class)(['stock_cycle_id' => $cycle->id, 'category_id' => categoryId('tyres'), 'incurred_on' => now()->toDateString(), 'gross_rp' => 61_000, 'commitment_id' => $promise->id]);
        app(CompleteCommitment::class)($promise);

        expect($promise->refresh()->cost_id)->toBe($cost->id)
            ->and($promise->countsAsEstimate())->toBeFalse()
            ->and($promise->done_by)->toBe($this->user->id);

        app(TransitionStockCycle::class)($cycle, StockCycleStatus::Delivered, data: ['mileage_out' => 10_100]);

        expect($cycle->refresh()->status)->toBe(StockCycleStatus::Delivered);
    });
});

it('records the purchase from the vehicle file', function () {
    useAppPanel($this->tenant, $this->user);

    $cycle = StockCycle::factory()->create();
    $seller = Party::factory()->company()->create();

    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->callAction('recordPurchase', [
            'seller_party_id' => $seller->id,
            'seller_kind' => 'company',
            'purchase_type' => 'direct',
            'contract_on' => now()->toDateString(),
            'price_rp' => "15'200.–",
            'vat_situation' => 'company_vat_shown',
            'vat_shown_rp' => '1139.65',
            'payment_status' => 'paid',
        ])
        ->assertHasNoActionErrors();

    $purchase = $cycle->refresh()->purchase;

    expect($cycle->status)->toBe(StockCycleStatus::Purchased)
        ->and($purchase->price_rp)->toBe(1_520_000)
        ->and($purchase->vat_shown_rp)->toBe(113_965);
});

it('adds and confirms costs in the vehicle file', function () {
    useAppPanel($this->tenant, $this->user);

    $cycle = StockCycle::factory()->status(StockCycleStatus::InPreparation)->create();

    Livewire::test(CostsRelationManager::class, ['ownerRecord' => $cycle, 'pageClass' => ViewStockCycle::class])
        ->callTableAction('create', data: [
            'category_id' => categoryId('repair'),
            'incurred_on' => now()->toDateString(),
            'description' => 'Brakes',
            'gross_rp' => '1’200',
        ])
        ->assertHasNoTableActionErrors();

    $cost = Cost::sole();

    expect($cost->stock_cycle_id)->toBe($cycle->id)
        ->and($cost->gross_rp)->toBe(120_000);

    Livewire::test(CostsRelationManager::class, ['ownerRecord' => $cycle, 'pageClass' => ViewStockCycle::class])
        ->callTableAction('confirm', $cost);

    expect($cost->refresh()->isConfirmed())->toBeTrue();
});
