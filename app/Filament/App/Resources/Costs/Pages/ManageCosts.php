<?php

namespace App\Filament\App\Resources\Costs\Pages;

use App\Domain\Purchasing\Actions\RecordCost;
use App\Domain\Purchasing\Actions\SplitCost;
use App\Domain\Purchasing\Models\Cost;
use App\Filament\App\Resources\Costs\CostResource;
use App\Filament\App\Resources\StockCycles\RelationManagers\CostsRelationManager;
use App\Support\BusinessRuleException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class ManageCosts extends ManageRecords
{
    protected static string $resource = CostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('split')
                ->label(__('Split an invoice across vehicles'))
                ->icon(Heroicon::OutlinedSquare2Stack)
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('create', Cost::class) ?? false)
                ->modalDescription(__('One supplier invoice for several cars, e.g. one transport of four vehicles. The amount is split equally.'))
                ->schema([
                    Select::make('stock_cycle_ids')
                        ->label(__('Vehicles'))
                        ->options(fn (): array => CostResource::cycleOptions(openOnly: true))
                        ->multiple()
                        ->searchable()
                        ->minItems(2)
                        ->required(),
                    ...CostsRelationManager::fields(null),
                ])
                ->action(function (array $data, Action $action): void {
                    $cycleIds = $data['stock_cycle_ids'];
                    unset($data['stock_cycle_ids']);

                    try {
                        $costs = app(SplitCost::class)($data, $cycleIds);
                    } catch (BusinessRuleException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()->title(__('Cost split across :count vehicles.', ['count' => $costs->count()]))->success()->send();
                }),
            CreateAction::make()
                ->using(function (array $data): Model {
                    try {
                        return app(RecordCost::class)($data);
                    } catch (BusinessRuleException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        throw new Halt;
                    }
                }),
        ];
    }
}
