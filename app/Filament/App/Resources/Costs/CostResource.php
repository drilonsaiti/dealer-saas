<?php

namespace App\Filament\App\Resources\Costs;

use App\Domain\Purchasing\Actions\ConfirmCost;
use App\Domain\Purchasing\Actions\RecordCost;
use App\Domain\Purchasing\Enums\CostStatus;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\Costs\Pages\ManageCosts;
use App\Filament\App\Resources\StockCycles\RelationManagers\CostsRelationManager;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * All costs: those of vehicle files and general operating costs (no vehicle).
 *
 * @extends resource<Cost>
 */
class CostResource extends Resource
{
    protected static ?string $model = Cost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('Cost');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Costs');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('stock_cycle_id')
                ->label(__('Vehicle file'))
                ->helperText(__('Leave empty for a general operating cost.'))
                ->options(fn (): array => self::cycleOptions())
                ->searchable()
                ->columnSpanFull(),
            ...CostsRelationManager::fields(null),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public static function cycleOptions(bool $openOnly = false): array
    {
        return StockCycle::query()
            ->with('vehicle')
            ->when($openOnly, fn (Builder $query) => $query->open())
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (StockCycle $cycle): array => [$cycle->id => $cycle->title()])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['category', 'supplier', 'stockCycle.vehicle']))
            ->columns([
                TextColumn::make('incurred_on')->label(__('Date'))->date()->sortable(),
                TextColumn::make('stockCycle.number')
                    ->label(__('Vehicle file'))
                    ->state(fn (Cost $record): string => $record->stockCycle?->title() ?? __('General operating cost'))
                    ->url(fn (Cost $record): ?string => $record->stockCycle === null ? null : StockCycleResource::getUrl('view', ['record' => $record->stockCycle]))
                    ->wrap(),
                TextColumn::make('category.name')->label(__('Category')),
                TextColumn::make('description')->label(__('Description'))->placeholder('–')->wrap()
                    ->description(fn (Cost $record): ?string => $record->supplier?->displayName())
                    ->searchable(),
                TextColumn::make('gross_rp')
                    ->label(__('Amount'))
                    ->formatStateUsing(fn (int $state): string => Money::format($state))
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Sum::make()->label(__('Total'))->formatStateUsing(fn (?int $state): string => Money::format($state ?? 0))),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->defaultSort('incurred_on', 'desc')
            ->filters([
                SelectFilter::make('category_id')->label(__('Category'))->options(fn (): array => CostCategory::options()),
                SelectFilter::make('status')->label(__('Status'))->options(CostStatus::class),
                TernaryFilter::make('general')
                    ->label(__('Vehicle file'))
                    ->trueLabel(__('General operating costs only'))
                    ->falseLabel(__('Vehicle costs only'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('stock_cycle_id'),
                        false: fn (Builder $query) => $query->whereNotNull('stock_cycle_id'),
                    ),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label(__('Confirm'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Cost $record): bool => $record->status === CostStatus::Draft && (auth()->user()?->can('confirm', $record) ?? false))
                    ->requiresConfirmation()
                    ->action(fn (Cost $record) => app(ConfirmCost::class)($record)),
                ActionGroup::make([
                    EditAction::make()->using(fn (Cost $record, array $data): Cost => app(RecordCost::class)($data, $record)),
                    DeleteAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCosts::route('/'),
        ];
    }
}
