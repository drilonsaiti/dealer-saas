<?php

use App\Domain\Purchasing\Models\Commitment;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Tenancy\Enums\Role;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\Costs\Pages\ManageCosts;
use App\Filament\App\Resources\StockCycles\Pages\ViewStockCycle;
use App\Filament\App\Resources\StockCycles\RelationManagers\CommitmentsRelationManager;
use App\Filament\App\Resources\StockCycles\RelationManagers\CostsRelationManager;
use Livewire\Livewire;

/*
 * Strict mode forbids lazy loading outside production. Row actions ask the policies, and the
 * policies need the vehicle file of each row, so they must load it explicitly.
 */

beforeEach(function () {
    $this->tenant = makeDealer(['name' => 'Garage A', 'slug' => 'garage-a']);
    $this->user = makeMember($this->tenant, Role::Sales);
    useAppPanel($this->tenant, $this->user);

    $this->cycle = StockCycle::factory()->status(StockCycleStatus::InPreparation)->create();
    // Two of each: Laravel only flags lazy loading on models read together in a list.
    $this->cost = Cost::factory()->for($this->cycle)->create();
    $this->otherCost = Cost::factory()->for($this->cycle)->create();
    $this->commitment = Commitment::factory()->for($this->cycle)->create();
    $this->otherCommitment = Commitment::factory()->for($this->cycle)->create();
});

it('shows costs of a vehicle file with their row actions', function () {
    Livewire::test(CostsRelationManager::class, ['ownerRecord' => $this->cycle, 'pageClass' => ViewStockCycle::class])
        ->assertCanSeeTableRecords([$this->cost, $this->otherCost])
        ->assertTableActionVisible('confirm', $this->cost)
        ->assertTableActionVisible('edit', $this->cost);
});

it('shows open promises of a vehicle file with their row actions', function () {
    Livewire::test(CommitmentsRelationManager::class, ['ownerRecord' => $this->cycle, 'pageClass' => ViewStockCycle::class])
        ->assertCanSeeTableRecords([$this->commitment, $this->otherCommitment])
        ->assertTableActionVisible('edit', $this->commitment);
});

it('shows the cost list with its row actions', function () {
    Livewire::test(ManageCosts::class)
        ->assertCanSeeTableRecords([$this->cost, $this->otherCost])
        ->assertTableActionVisible('edit', $this->cost);
});

it('loads the vehicle file when a policy checks costs read together from the database', function () {
    $costs = Cost::query()->get();
    $commitments = Commitment::query()->get();

    expect($costs)->toHaveCount(2)
        ->and($costs->every(fn (Cost $cost): bool => $this->user->can('update', $cost)))->toBeTrue()
        ->and($commitments->every(fn (Commitment $commitment): bool => $this->user->can('update', $commitment)))->toBeTrue();
});
