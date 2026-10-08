<?php

namespace App\Filament\App\Resources\StockCycles;

use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Support\Stammnummer;
use App\Filament\App\Resources\StockCycles\Pages\CreateStockCycle;
use App\Filament\App\Resources\StockCycles\Pages\EditStockCycle;
use App\Filament\App\Resources\StockCycles\Pages\ListStockCycles;
use App\Filament\App\Resources\StockCycles\Pages\ViewStockCycle;
use App\Filament\App\Resources\StockCycles\RelationManagers\StatusHistoryRelationManager;
use App\Filament\App\Resources\StockCycles\RelationManagers\TyreSetsRelationManager;
use App\Filament\App\Resources\StockCycles\Schemas\StockCycleInfolist;
use App\Support\Money;
use App\Support\SwissFormat;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Vehicles: the stock list and the vehicle file (one per purchase-to-sale cycle).
 *
 * @extends resource<StockCycle>
 */
class StockCycleResource extends Resource
{
    protected static ?string $model = StockCycle::class;

    protected static ?string $slug = 'vehicles';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('Vehicle file');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Vehicles');
    }

    public static function getNavigationLabel(): string
    {
        return __('Vehicles');
    }

    public static function infolist(Schema $schema): Schema
    {
        return StockCycleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('vehicle'))
            ->columns([
                TextColumn::make('number')
                    ->label(__('File'))
                    ->placeholder('–')
                    ->fontFamily('mono')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('vehicle.make')
                    ->label(__('Vehicle'))
                    ->state(fn (StockCycle $record): string => $record->vehicle->displayName())
                    ->description(fn (StockCycle $record): ?string => $record->vehicle->internal_label !== $record->vehicle->displayName() ? $record->vehicle->internal_label : null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::searchVehicles($query, $search))
                    ->wrap(),
                TextColumn::make('vehicle.stammnummer')
                    ->label(__('Stammnummer'))
                    ->formatStateUsing(fn (?string $state): ?string => Stammnummer::format($state))
                    ->placeholder('–')
                    ->fontFamily('mono')
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('purchased_on')
                    ->label(__('Purchased'))
                    ->date()
                    ->placeholder('–')
                    ->sortable(),
                TextColumn::make('days_in_stock')
                    ->label(__('Days in stock'))
                    ->state(fn (StockCycle $record): ?int => $record->daysInStock())
                    ->placeholder('–')
                    ->numeric()
                    ->color(fn (?int $state, StockCycle $record): ?string => $record->status->isInStock() && $state !== null && $state > 90 ? 'danger' : ($record->status->isInStock() && $state > 60 ? 'warning' : null))
                    ->alignEnd(),
                TextColumn::make('mileage_in')
                    ->label(__('Mileage'))
                    ->formatStateUsing(fn (?int $state): string => SwissFormat::mileage($state))
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('list_price_rp')
                    ->label(__('List price'))
                    ->formatStateUsing(fn (?int $state): string => Money::format($state))
                    ->placeholder('–')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('file_year')
                    ->label(__('File year'))
                    ->placeholder('–')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('purchased_on')->orderByDesc('created_at'))
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(StockCycleStatus::class)
                    ->multiple(),
                SelectFilter::make('file_year')
                    ->label(__('File year'))
                    ->options(fn (): array => StockCycle::query()
                        ->whereNotNull('file_year')
                        ->distinct()
                        ->orderByDesc('file_year')
                        ->pluck('file_year', 'file_year')
                        ->all()),
            ])
            ->recordUrl(fn (StockCycle $record): string => self::getUrl('view', ['record' => $record]));
    }

    /**
     * Search by name, VIN, plate and Stammnummer, with or without dots ("683.737.537").
     *
     * @param  Builder<StockCycle>  $query
     * @return Builder<StockCycle>
     */
    public static function searchVehicles(Builder $query, string $search): Builder
    {
        $term = mb_strtolower(trim($search));
        $digits = preg_replace('/\D/', '', $search) ?? '';

        return $query->whereHas('vehicle', function (Builder $vehicle) use ($term, $digits): void {
            $vehicle->where(function (Builder $where) use ($term, $digits): void {
                foreach (['make', 'model', 'variant', 'internal_label', 'vin', 'plate'] as $column) {
                    $where->orWhereRaw("lower({$column}) like ?", ["%{$term}%"]);
                }

                if (strlen($digits) >= 3) {
                    $where->orWhere('stammnummer', 'like', "%{$digits}%");
                }
            });
        });
    }

    public static function getRelations(): array
    {
        return [
            StatusHistoryRelationManager::class,
            TyreSetsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockCycles::route('/'),
            'create' => CreateStockCycle::route('/create'),
            'view' => ViewStockCycle::route('/{record}'),
            'edit' => EditStockCycle::route('/{record}/edit'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['number'];
    }

    /**
     * @param  Builder<StockCycle>  $query
     */
    public static function modifyGlobalSearchQuery(Builder $query, string $search): void
    {
        $query->orWhere(fn (Builder $or) => self::searchVehicles($or, $search));
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('vehicle');
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var StockCycle $record */
        return $record->title();
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var StockCycle $record */
        return array_filter([
            __('Stammnummer') => $record->vehicle->formattedStammnummer(),
            __('Status') => $record->status->getLabel(),
        ]);
    }
}
