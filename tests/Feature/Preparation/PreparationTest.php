<?php

use App\Domain\Parties\Models\Party;
use App\Domain\Preparation\Actions\ManageRepairOrder;
use App\Domain\Preparation\Actions\RecordConditionReport;
use App\Domain\Preparation\Actions\ReleaseForSale;
use App\Domain\Preparation\Enums\RepairOrderStatus;
use App\Domain\Preparation\Models\Damage;
use App\Domain\Preparation\Models\RepairOrder;
use App\Domain\Purchasing\Actions\RecordPurchase;
use App\Domain\Purchasing\Enums\CostStatus;
use App\Domain\Reporting\CalculateMargin;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\StockCycles\Pages\ViewStockCycle;
use App\Filament\App\Resources\StockCycles\RelationManagers\ConditionReportsRelationManager;
use App\Filament\App\Resources\StockCycles\RelationManagers\RepairOrdersRelationManager;
use App\Support\BusinessRuleException;
use Filament\Forms\Components\Repeater;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
 * Preparation (concept 10.5): condition report with damages and photos, repair orders
 * (estimate → approved → actual cost → done) and "release for sale".
 */

beforeEach(function () {
    app()->setLocale('en');
    $this->tenant = makeDealer(['slug' => 'aziri']);
    $this->user = makeMember($this->tenant, Role::Sales);
    $this->actingAs($this->user);
});

function arrivedCycle(): StockCycle
{
    $cycle = StockCycle::factory()->create(['mileage_in' => 64_500, 'list_price_rp' => 2_290_000]);
    app(RecordPurchase::class)($cycle, ['seller_party_id' => Party::factory()->create()->id, 'seller_kind' => 'company', 'contract_on' => '2026-09-19', 'price_rp' => 1_880_000]);
    app(TransitionStockCycle::class)($cycle->refresh(), StockCycleStatus::Arrived);

    return $cycle->refresh();
}

it('records damages with photos, repairs them and releases the car for sale', function () {
    asTenant($this->tenant, function () {
        $cycle = arrivedCycle();
        $report = app(RecordConditionReport::class)($cycle, [
            'mileage' => 64_510,
            'items' => ['exterior' => ['rating' => 'attention', 'note' => 'Delle hinten links'], 'tyres' => ['rating' => 'defect', 'note' => 'Sommerreifen 2 mm']],
            'summary' => 'Guter Zustand, Delle und Reifen machen.',
        ], [
            ['area' => 'left', 'kind' => 'dent', 'severity' => 'medium', 'notes' => 'Tür hinten', 'photos' => [UploadedFile::fake()->image('delle.jpg')]],
            ['area' => 'wheels', 'kind' => 'wear', 'severity' => 'major'],
        ]);

        $dent = $report->damages->firstWhere('kind.value', 'dent');

        expect($report->damages)->toHaveCount(2)
            ->and($dent->photos()->count())->toBe(1)
            ->and($dent->photos()->with('category')->first()->category->key)->toBe('photo');

        // Estimate → approved (counts in the margin) → done (real cost).
        $workshop = Party::factory()->create(['kind' => 'company', 'company_name' => 'Carrosserie Muster']);
        $order = app(ManageRepairOrder::class)->create($cycle, ['workshop_party_id' => $workshop->id, 'description' => 'Delle Tür hinten links ausbeulen', 'estimate_rp' => 45_000], [$dent->id]);
        $tyres = app(ManageRepairOrder::class)->create($cycle, ['description' => '4 Sommerreifen', 'estimate_rp' => 60_000]);

        expect($dent->refresh()->repair_order_id)->toBe($order->id)
            ->and(fn () => app(ReleaseForSale::class)($cycle))->toThrow(BusinessRuleException::class, 'Repair orders still open: 2');

        app(ManageRepairOrder::class)->approve($order, 42_000);
        $margin = app(CalculateMargin::class)($cycle->refresh());

        expect($margin->openRepairsRp)->toBe(42_000)
            ->and($margin->costsRp())->toBe(42_000)
            ->and($margin->isProvisional)->toBeTrue();

        app(ManageRepairOrder::class)->done($order->refresh(), 43_500, '2026-09-30');
        app(ManageRepairOrder::class)->cancel($tyres);
        $order->refresh();

        expect($order->status)->toBe(RepairOrderStatus::Done)
            ->and($order->cost->gross_rp)->toBe(43_500)
            ->and($order->cost->status)->toBe(CostStatus::Confirmed)
            ->and($order->cost->supplier_party_id)->toBe($workshop->id)
            ->and(app(CalculateMargin::class)($cycle->refresh())->costsRp())->toBe(43_500);

        app(ReleaseForSale::class)($cycle->refresh());

        expect($cycle->refresh()->status)->toBe(StockCycleStatus::ReadyForSale)
            ->and($cycle->released_for_sale_at)->not->toBeNull()
            ->and($cycle->released_for_sale_by)->toBe($this->user->id);
    });
});

it('lets a non-blocking repair order stay open, and guards every way to "ready for sale"', function () {
    asTenant($this->tenant, function () {
        $cycle = arrivedCycle();
        $polish = app(ManageRepairOrder::class)->create($cycle, ['description' => 'Politur', 'blocks_release' => false]);
        $brakes = app(ManageRepairOrder::class)->create($cycle, ['description' => 'Bremsen vorne']);

        expect(fn () => app(TransitionStockCycle::class)($cycle, StockCycleStatus::ReadyForSale))->toThrow(BusinessRuleException::class, 'Repair orders still open: 1');

        app(ManageRepairOrder::class)->done($brakes, 0);

        expect(app(ReleaseForSale::class)($cycle->refresh())->status)->toBe(StockCycleStatus::ReadyForSale)
            ->and($polish->refresh()->status)->toBe(RepairOrderStatus::Estimate)
            ->and(fn () => app(ManageRepairOrder::class)->approve($brakes->refresh(), 100))->toThrow(BusinessRuleException::class, 'Only an estimate');
    });
});

it('works from the vehicle file screens', function () {
    useAppPanel($this->tenant, $this->user);
    Repeater::fake();
    $cycle = arrivedCycle();

    Livewire::test(ConditionReportsRelationManager::class, ['ownerRecord' => $cycle, 'pageClass' => ViewStockCycle::class])
        ->callTableAction('report', data: [
            'reported_on' => '2026-09-21',
            'mileage' => 64_510,
            'items' => ['exterior' => ['rating' => 'attention', 'note' => 'Kratzer'], 'interior' => ['rating' => 'ok']],
            'damages' => [['area' => 'front', 'kind' => 'scratch', 'severity' => 'minor', 'notes' => 'Stossstange']],
        ])
        ->assertHasNoTableActionErrors();

    $damage = Damage::query()->sole();

    Livewire::test(RepairOrdersRelationManager::class, ['ownerRecord' => $cycle, 'pageClass' => ViewStockCycle::class])
        ->callTableAction('create', data: ['description' => 'Kratzer polieren', 'estimate_rp' => '150', 'damages' => [$damage->id], 'blocks_release' => true])
        ->assertHasNoTableActionErrors();

    $order = RepairOrder::query()->sole();

    Livewire::test(RepairOrdersRelationManager::class, ['ownerRecord' => $cycle, 'pageClass' => ViewStockCycle::class])
        ->callTableAction('approve', $order, data: ['approved_rp' => '150'])
        ->callTableAction('done', $order->refresh(), data: ['actual_rp' => '160', 'done_on' => '2026-09-25'])
        ->assertHasNoTableActionErrors();

    Livewire::test(ViewStockCycle::class, ['record' => $cycle->getRouteKey()])
        ->callAction('releaseForSale')
        ->assertHasNoActionErrors();

    expect($order->refresh()->cost->gross_rp)->toBe(16_000)
        ->and($damage->refresh()->repair_order_id)->toBe($order->id)
        ->and($cycle->refresh()->status)->toBe(StockCycleStatus::ReadyForSale);
});
