<?php

namespace App\Filament\App\Resources\StockCycles\RelationManagers;

use App\Domain\Vehicles\Enums\TyreSeason;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\TyreSet;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Tyre sets belong to the car, so they show up in every file of that car.
 */
class TyreSetsRelationManager extends RelationManager
{
    protected static string $relationship = 'tyreSets';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Tyres');
    }

    public function isReadOnly(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof StockCycle && $owner->isLocked();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                Select::make('season')->label(__('Season'))->options(TyreSeason::class)->required(),
                TextInput::make('dimension')->label(__('Dimension'))->placeholder('225/45 R17')->maxLength(40),
                TextInput::make('tread_mm')->label(__('Tread depth (mm)'))->numeric()->minValue(0)->maxValue(20)->step(0.1),
                TextInput::make('rim_type')->label(__('Rims'))->placeholder(__('e.g. alloy 17"'))->maxLength(40),
                Toggle::make('on_rims')->label(__('Mounted on rims')),
                TextInput::make('location')->label(__('Storage location'))->maxLength(120),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('season')->label(__('Season'))->badge(),
                TextColumn::make('dimension')->label(__('Dimension'))->placeholder('–'),
                TextColumn::make('tread_mm')->label(__('Tread depth (mm)'))->placeholder('–'),
                IconColumn::make('on_rims')->label(__('Mounted on rims'))->boolean(),
                TextColumn::make('location')->label(__('Storage location'))->placeholder('–'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        /** @var StockCycle $owner */
                        $owner = $this->getOwnerRecord();
                        $data['vehicle_id'] = $owner->vehicle_id;

                        return $data;
                    })
                    ->using(fn (array $data): TyreSet => TyreSet::create($data)),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
