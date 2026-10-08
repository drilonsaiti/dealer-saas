<?php

namespace App\Filament\App\Resources\StockCycles\Pages;

use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\StockCycles\Schemas\VehicleForm;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use App\Filament\Support\MoneyInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Edits the car's data and the file's prices. Status, number and dates are not editable
 * here: they change only through the status actions.
 */
class EditStockCycle extends EditRecord
{
    protected static string $resource = StockCycleResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Vehicle file'))
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('mileage_in')->label(__('Mileage at purchase'))->integer()->minValue(0)->suffix('km'),
                        MoneyInput::make('planned_price_rp')->label(__('Planned price')),
                        MoneyInput::make('list_price_rp')->label(__('List price')),
                    ]),
                    Textarea::make('notes')->label(__('Notes'))->rows(2),
                ]),
            Group::make(VehicleForm::sections(fn (): ?string => $this->vehicleId(), allowKnownVehicle: false))
                ->relationship('vehicle')
                ->columnSpanFull(),
        ]);
    }

    private function vehicleId(): ?string
    {
        $record = $this->getRecord();

        return $record instanceof StockCycle ? $record->vehicle_id : null;
    }

    protected function getRedirectUrl(): string
    {
        return StockCycleResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
