<?php

namespace App\Filament\App\Resources\BuybackObligations;

use App\Domain\Financing\Actions\ExerciseBuyback;
use App\Domain\Financing\Enums\BuybackStatus;
use App\Domain\Financing\Models\BuybackObligation;
use App\Filament\App\Resources\BuybackObligations\Pages\ListBuybackObligations;
use App\Filament\App\Resources\Financings\FinancingResource;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use App\Support\BusinessRuleException;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Finance → Buy-back obligations: what the dealer must buy back from leasing banks at lease
 * end (a contingent liability), with reminders; exercising opens a new vehicle file.
 *
 * @extends resource<BuybackObligation>
 */
class BuybackObligationResource extends Resource
{
    protected static ?string $model = BuybackObligation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static ?int $navigationSort = 36;

    protected static ?string $slug = 'buy-backs';

    public static function getNavigationGroup(): string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Buy-back obligation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Buy-back obligations');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = BuybackObligation::query()->where('status', BuybackStatus::Open->value)->whereDate('remind_on', '<=', Carbon::today())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Due within 3 months');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['vehicle', 'financing.partner', 'stockCycle']))
            ->columns([
                TextColumn::make('vehicle')->label(__('Vehicle'))->state(fn (BuybackObligation $record): string => trim($record->vehicle->make.' '.$record->vehicle->model))
                    ->description(fn (BuybackObligation $record): ?string => $record->vehicle->stammnummer),
                TextColumn::make('financing.partner')->label(__('Bank'))->state(fn (BuybackObligation $record): string => $record->financing->partner->displayName())
                    ->url(fn (BuybackObligation $record): string => FinancingResource::getUrl('view', ['record' => $record->financing])),
                TextColumn::make('amount_rp')->label(__('Amount'))->alignEnd()->formatStateUsing(fn (int $state): string => Money::format($state))->sortable(),
                TextColumn::make('due_on')->label(__('Due'))->date()->sortable()
                    ->color(fn (BuybackObligation $record): ?string => $record->status === BuybackStatus::Open && $record->remind_on->lessThanOrEqualTo(Carbon::today()) ? 'warning' : null),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->defaultSort('due_on')
            ->recordActions([
                Action::make('exercise')
                    ->label(__('Buy back'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->visible(fn (BuybackObligation $record): bool => $record->status === BuybackStatus::Open && (auth()->user()?->can('update', $record) ?? false))
                    ->modalDescription(__('Opens a new vehicle file on the same vehicle, bought from the bank (purchase type buy-back) at the agreed amount.'))
                    ->schema([
                        DatePicker::make('on')->label(__('Date'))->default(now())->required(),
                        TextInput::make('mileage')->label(__('Mileage'))->integer()->minValue(0)->suffix('km'),
                    ])
                    ->action(function (BuybackObligation $record, array $data, Action $action): void {
                        try {
                            $cycle = app(ExerciseBuyback::class)($record, (string) $data['on'], filled($data['mileage'] ?? null) ? (int) $data['mileage'] : null);
                        } catch (BusinessRuleException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                            $action->halt();

                            return;
                        }

                        $action->redirect(StockCycleResource::getUrl('view', ['record' => $cycle]));
                    }),
                Action::make('release')
                    ->label(__('Released'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->visible(fn (BuybackObligation $record): bool => $record->status === BuybackStatus::Open && (auth()->user()?->can('update', $record) ?? false))
                    ->schema([TextInput::make('note')->label(__('Note'))->maxLength(255)])
                    ->action(fn (BuybackObligation $record, array $data) => app(ExerciseBuyback::class)->release($record, $data['note'] ?? null)),
                Action::make('file')
                    ->label(__('Vehicle file'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->visible(fn (BuybackObligation $record): bool => $record->stockCycle !== null)
                    ->url(fn (BuybackObligation $record): ?string => $record->stockCycle === null ? null : StockCycleResource::getUrl('view', ['record' => $record->stockCycle])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBuybackObligations::route('/'),
        ];
    }
}
