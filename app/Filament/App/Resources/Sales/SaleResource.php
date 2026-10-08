<?php

namespace App\Filament\App\Resources\Sales;

use App\Domain\Reporting\CalculateMargin;
use App\Domain\Sales\Enums\PaymentType;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Filament\App\Resources\Sales\Pages\ListSales;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sales overview with buyer, price and margin. Sales are created and changed in the vehicle file.
 *
 * @extends resource<Sale>
 */
class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?int $navigationSort = 15;

    public static function getModelLabel(): string
    {
        return __('Sale');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Sales');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['stockCycle.vehicle', 'stockCycle.purchase', 'buyer', 'items', 'tradeIn']))
            ->columns([
                TextColumn::make('sale_on')->label(__('Contract date'))->date()->placeholder('–')->sortable(),
                TextColumn::make('stockCycle.number')
                    ->label(__('Vehicle'))
                    ->state(fn (Sale $record): string => $record->stockCycle->title())
                    ->wrap(),
                TextColumn::make('buyer.last_name')->label(__('Buyer'))->state(fn (Sale $record): string => $record->buyer->displayName()),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('payment_type')->label(__('Payment'))->toggleable(),
                TextColumn::make('total')->label(__('Total'))->state(fn (Sale $record): string => Money::format($record->totalRp()))->alignEnd(),
                TextColumn::make('margin')
                    ->label(__('Margin'))
                    ->state(function (Sale $record): string {
                        if ($record->status === SaleStatus::Cancelled) {
                            return '–';
                        }

                        $margin = app(CalculateMargin::class)($record->stockCycle);

                        return Money::format($margin->marginRp()).($margin->isProvisional ? ' *' : '');
                    })
                    ->tooltip(__('* provisional'))
                    ->alignEnd(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('sale_on')->orderByDesc('created_at'))
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(SaleStatus::class)->multiple()
                    ->default([SaleStatus::Reserved->value, SaleStatus::Contracted->value, SaleStatus::Invoiced->value, SaleStatus::Delivered->value]),
                SelectFilter::make('payment_type')->label(__('Payment'))->options(PaymentType::class),
                Filter::make('sale_on')
                    ->schema([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $from) => $q->where('sale_on', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $q, string $until) => $q->where('sale_on', '<=', $until))),
            ])
            ->recordUrl(fn (Sale $record): string => StockCycleResource::getUrl('view', ['record' => $record->stockCycle]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSales::route('/'),
        ];
    }
}
