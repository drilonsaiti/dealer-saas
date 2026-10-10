<?php

namespace App\Filament\App\Widgets;

use App\Domain\Pricing\Actions\SuggestPrice;
use App\Domain\Tenancy\Enums\Permission;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use App\Models\User;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The cars that have been standing longest: the first ones to act on.
 */
class LongestInStock extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasPermission(Permission::VehiclesView);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Longest in stock'))
            ->query(fn () => StockCycle::query()->inStock()->whereNotNull('purchased_on')->with(['vehicle', 'purchase'])->orderBy('purchased_on')->limit(8))
            ->paginated(false)
            ->columns([
                TextColumn::make('number')->label(__('File'))->fontFamily('mono'),
                TextColumn::make('vehicle_name')->label(__('Vehicle'))->state(fn (StockCycle $record): string => $record->vehicle->displayName()),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('days')
                    ->label(__('Days in stock'))
                    ->state(fn (StockCycle $record): ?int => $record->daysInStock())
                    ->color(fn (?int $state): ?string => $state !== null && $state > 90 ? 'danger' : ($state !== null && $state > 60 ? 'warning' : null))
                    ->alignEnd(),
                TextColumn::make('list_price_rp')->label(__('List price'))->formatStateUsing(fn (?int $state): string => Money::format($state))->placeholder('–')->alignEnd(),
                TextColumn::make('suggested')->label(__('Suggested price'))->alignEnd()->color('warning')
                    ->state(function (StockCycle $record): ?string {
                        $suggestion = app(SuggestPrice::class)($record);

                        return $suggestion !== null && $suggestion->lowersPrice() ? Money::format($suggestion->suggestedRp) : null;
                    })
                    ->placeholder('–'),
            ])
            ->recordUrl(fn (StockCycle $record): string => StockCycleResource::getUrl('view', ['record' => $record]));
    }
}
