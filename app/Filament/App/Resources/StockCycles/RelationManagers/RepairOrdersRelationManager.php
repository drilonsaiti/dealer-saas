<?php

namespace App\Filament\App\Resources\StockCycles\RelationManagers;

use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Preparation\Actions\ManageRepairOrder;
use App\Domain\Preparation\Enums\RepairOrderStatus;
use App\Domain\Preparation\Models\Damage;
use App\Domain\Preparation\Models\RepairOrder;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\PartySelect;
use App\Support\BusinessRuleException;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Repair orders to workshops: estimate → approved → done (actual cost booked), or cancelled.
 * Open orders that block the release keep the car from "ready for sale".
 */
class RepairOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'repairOrders';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Repair orders');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['workshop', 'damages']))
            ->columns([
                TextColumn::make('description')->label(__('Work'))->wrap()->limit(100)
                    ->description(fn (RepairOrder $record): ?string => $record->workshop?->displayName()),
                TextColumn::make('estimate_rp')->label(__('Estimate'))->alignEnd()->formatStateUsing(fn (?int $state): string => $state === null ? '–' : Money::format($state))->placeholder('–'),
                TextColumn::make('approved_rp')->label(__('Approved'))->alignEnd()->formatStateUsing(fn (?int $state): string => $state === null ? '–' : Money::format($state))->placeholder('–'),
                TextColumn::make('cost.gross_rp')->label(__('Actual'))->alignEnd()->formatStateUsing(fn (?int $state): string => $state === null ? '–' : Money::format($state))->placeholder('–'),
                TextColumn::make('target_on')->label(__('Target date'))->date()->placeholder('–')
                    ->color(fn (RepairOrder $record): ?string => $record->status->isOpen() && $record->target_on?->isPast() ? 'danger' : null),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->description(fn (RepairOrder $record): ?string => $record->status->isOpen() && $record->blocks_release ? __('blocks the release') : null),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Action::make('create')
                    ->label(__('New repair order'))
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->visible(fn (): bool => auth()->user()?->can('create', RepairOrder::class) ?? false)
                    ->schema(fn (): array => [
                        PartySelect::make('workshop_party_id', [PartyRole::Workshop])->label(__('Workshop')),
                        Textarea::make('description')->label(__('Work'))->rows(3)->required(),
                        CheckboxList::make('damages')->label(__('Damages to repair'))
                            ->options(Damage::query()->where('stock_cycle_id', $this->cycle()->getKey())->whereNull('repair_order_id')->get()->mapWithKeys(fn (Damage $d): array => [$d->getKey() => $d->label()])->all())
                            ->visible(Damage::query()->where('stock_cycle_id', $this->cycle()->getKey())->whereNull('repair_order_id')->exists()),
                        Grid::make(2)->schema([
                            MoneyInput::make('estimate_rp')->label(__('Estimate'))->nullable(),
                            DatePicker::make('target_on')->label(__('Target date')),
                        ]),
                        Toggle::make('blocks_release')->label(__('Must be done before the car is ready for sale'))->default(true),
                    ])
                    ->action(fn (array $data, Action $action) => $this->run($action, fn () => app(ManageRepairOrder::class)->create($this->cycle(), collect($data)->except('damages')->all(), array_values($data['damages'] ?? [])), __('Repair order created.'))),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon(Heroicon::OutlinedHandThumbUp)
                    ->visible(fn (RepairOrder $record): bool => $record->status === RepairOrderStatus::Estimate && (auth()->user()?->can('update', $record) ?? false))
                    ->fillForm(fn (RepairOrder $record): array => ['approved_rp' => $record->estimate_rp])
                    ->schema([MoneyInput::make('approved_rp')->label(__('Approved amount'))->required()])
                    ->action(fn (RepairOrder $record, array $data, Action $action) => $this->run($action, fn () => app(ManageRepairOrder::class)->approve($record, (int) $data['approved_rp']), __('Approved: counts in the margin until the invoice is in.'))),
                Action::make('done')
                    ->label(__('Done'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (RepairOrder $record): bool => $record->status->isOpen() && (auth()->user()?->can('update', $record) ?? false))
                    ->fillForm(fn (RepairOrder $record): array => ['actual_rp' => $record->approved_rp ?? $record->estimate_rp, 'done_on' => now()->toDateString()])
                    ->schema([
                        MoneyInput::make('actual_rp')->label(__('Actual cost (workshop invoice, gross)'))->required(),
                        DatePicker::make('done_on')->label(__('Done on'))->required(),
                    ])
                    ->action(fn (RepairOrder $record, array $data, Action $action) => $this->run($action, fn () => app(ManageRepairOrder::class)->done($record, (int) $data['actual_rp'], (string) $data['done_on']), __('Done: the cost is booked.'))),
                Action::make('cancel')
                    ->label(__('Cancel'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->visible(fn (RepairOrder $record): bool => $record->status->isOpen() && (auth()->user()?->can('update', $record) ?? false))
                    ->requiresConfirmation()
                    ->action(fn (RepairOrder $record, Action $action) => $this->run($action, fn () => app(ManageRepairOrder::class)->cancel($record), __('Cancelled.'))),
            ])
            ->paginated(false);
    }

    private function cycle(): StockCycle
    {
        /** @var StockCycle $cycle */
        $cycle = $this->getOwnerRecord();

        return $cycle;
    }

    private function run(Action $action, Closure $callback, string $success): void
    {
        try {
            $callback();
        } catch (BusinessRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();
        }

        Notification::make()->title($success)->success()->send();
    }
}
