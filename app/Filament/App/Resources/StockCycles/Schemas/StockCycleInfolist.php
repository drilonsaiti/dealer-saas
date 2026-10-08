<?php

namespace App\Filament\App\Resources\StockCycles\Schemas;

use App\Domain\Vehicles\Models\StockCycle;
use App\Support\Money;
use App\Support\SwissFormat;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The vehicle file's overview: where the car stands, what it cost, and what it is.
 */
final class StockCycleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Vehicle file'))
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('status')->label(__('Status'))->badge(),
                        TextEntry::make('number')->label(__('File'))->placeholder(__('assigned on purchase'))->fontFamily('mono'),
                        TextEntry::make('file_year')->label(__('File year'))->placeholder('–'),
                        TextEntry::make('days_in_stock')
                            ->label(__('Days in stock'))
                            ->state(fn (StockCycle $record): ?int => $record->daysInStock())
                            ->placeholder('–'),
                        TextEntry::make('purchased_on')->label(__('Purchased'))->date()->placeholder('–'),
                        TextEntry::make('ready_on')->label(__('Ready for sale'))->date()->placeholder('–'),
                        TextEntry::make('listed_on')->label(__('Listed'))->date()->placeholder('–'),
                        TextEntry::make('sold_on')->label(__('Sold'))->date()->placeholder('–'),
                        TextEntry::make('planned_price_rp')->label(__('Planned price'))->formatStateUsing(fn (?int $state): string => Money::format($state))->placeholder('–'),
                        TextEntry::make('list_price_rp')->label(__('List price'))->formatStateUsing(fn (?int $state): string => Money::format($state))->placeholder('–'),
                        TextEntry::make('mileage_in')->label(__('Mileage at purchase'))->formatStateUsing(fn (?int $state): string => SwissFormat::mileage($state))->placeholder('–'),
                        TextEntry::make('mileage_out')->label(__('Mileage at handover'))->formatStateUsing(fn (?int $state): string => SwissFormat::mileage($state))->placeholder('–'),
                    ]),
                    TextEntry::make('notes')->label(__('Notes'))->placeholder('–')->columnSpanFull(),
                ]),
            Section::make(__('Vehicle'))
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('vehicle.make')->label(__('Make'))->placeholder('–'),
                        TextEntry::make('vehicle.model')->label(__('Model'))->placeholder('–'),
                        TextEntry::make('vehicle.variant')->label(__('Version'))->placeholder('–'),
                        TextEntry::make('vehicle.internal_label')->label(__('Internal label'))->placeholder('–'),
                        TextEntry::make('vehicle.stammnummer')
                            ->label(__('Stammnummer'))
                            ->state(fn (StockCycle $record): ?string => $record->vehicle->formattedStammnummer())
                            ->placeholder('–')
                            ->fontFamily('mono')
                            ->copyable(),
                        TextEntry::make('vehicle.vin')->label(__('VIN'))->placeholder('–')->fontFamily('mono')->copyable(),
                        TextEntry::make('vehicle.plate')->label(__('Plate'))->placeholder('–'),
                        TextEntry::make('vehicle.type_approval')->label(__('Type approval'))->placeholder('–'),
                        TextEntry::make('vehicle.vehicle_type')->label(__('Vehicle type'))->placeholder('–'),
                        TextEntry::make('vehicle.body_type')->label(__('Body type'))->placeholder('–'),
                        TextEntry::make('vehicle.fuel')->label(__('Fuel'))->placeholder('–'),
                        TextEntry::make('vehicle.transmission')->label(__('Transmission'))->placeholder('–'),
                        TextEntry::make('vehicle.drive')->label(__('Drive'))->placeholder('–'),
                        TextEntry::make('vehicle.first_registration_on')->label(__('First registration'))->date()->placeholder('–'),
                        TextEntry::make('vehicle.power_kw')->label(__('Power (kW)'))->placeholder('–'),
                        TextEntry::make('vehicle.displacement_cc')->label(__('Displacement (cm³)'))->formatStateUsing(fn (?int $state): string => SwissFormat::number($state))->placeholder('–'),
                        TextEntry::make('vehicle.color_exterior')->label(__('Exterior colour'))->placeholder('–'),
                        TextEntry::make('vehicle.color_interior')->label(__('Interior colour'))->placeholder('–'),
                        TextEntry::make('vehicle.doors')->label(__('Doors'))->placeholder('–'),
                        TextEntry::make('vehicle.seats')->label(__('Seats'))->placeholder('–'),
                    ]),
                ]),
            Section::make(__('Registration, inspection and service'))
                ->collapsible()
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('vehicle.last_registration_on')->label(__('Last registration'))->date()->placeholder('–'),
                        TextEntry::make('vehicle.mfk_last_on')->label(__('Last MFK'))->date()->placeholder('–'),
                        TextEntry::make('vehicle.mfk_due_on')->label(__('Next MFK due'))->date()->placeholder('–'),
                        TextEntry::make('vehicle.keys_count')->label(__('Number of keys'))->placeholder('–'),
                        TextEntry::make('vehicle.service_last_on')->label(__('Last service'))->date()->placeholder('–'),
                        TextEntry::make('vehicle.service_last_km')->label(__('Last service at (km)'))->formatStateUsing(fn (?int $state): string => SwissFormat::mileage($state))->placeholder('–'),
                        TextEntry::make('vehicle.service_next_on')->label(__('Next service due'))->date()->placeholder('–'),
                        TextEntry::make('vehicle.curb_weight_kg')->label(__('Curb weight (kg)'))->formatStateUsing(fn (?int $state): string => SwissFormat::number($state))->placeholder('–'),
                    ]),
                ]),
            Section::make(__('Equipment and notes'))
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextEntry::make('vehicle.equipment')->label(__('Equipment'))->badge()->placeholder('–'),
                    TextEntry::make('vehicle.internal_notes')->label(__('Internal notes'))->placeholder('–'),
                ]),
        ]);
    }
}
