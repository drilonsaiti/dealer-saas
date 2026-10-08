<?php

namespace App\Filament\App\Resources\StockCycles\Actions;

use App\Domain\Purchasing\Actions\RecordPurchase;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Vehicles\Actions\OpenStockCycle;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\StockCycles\Schemas\PurchaseForm;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use App\Support\BusinessRuleException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Header actions of the vehicle file. They only collect input and call the domain actions.
 */
final class StockCycleActions
{
    /**
     * One entry per status the file can move to by hand. Purchases, reservations and sales
     * get their status from their own actions, so they are not offered here.
     */
    public static function changeStatus(): ActionGroup
    {
        $actions = [];

        foreach (StockCycleStatus::cases() as $to) {
            if ($to->isSetByAction()) {
                continue;
            }

            $actions[] = self::transitionTo($to);
        }

        return ActionGroup::make($actions)
            ->label(__('Change status'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->button()
            ->color('primary');
    }

    private static function transitionTo(StockCycleStatus $to): Action
    {
        return Action::make('status_'.$to->value)
            ->label(fn (StockCycle $record): string => $record->status->isBackStepTo($to)
                ? __('Back to ":status"', ['status' => $to->getLabel()])
                : $to->getLabel())
            ->color($to === StockCycleStatus::Cancelled ? 'danger' : null)
            ->visible(fn (StockCycle $record): bool => (auth()->user()?->can('transition', $record) ?? false)
                && $record->status->canTransitionTo($to))
            ->modalHeading(fn (StockCycle $record): string => __('Change status to ":status"', ['status' => $to->getLabel()]))
            ->modalSubmitActionLabel(__('Change status'))
            ->schema(fn (StockCycle $record): array => array_values(array_filter([
                in_array($to, [StockCycleStatus::ReadyForSale, StockCycleStatus::Listed, StockCycleStatus::Delivered, StockCycleStatus::Archived], true)
                    ? DatePicker::make('on')->label(__('Date'))->default(now())->maxDate(now())->required()
                    : null,
                $to === StockCycleStatus::Delivered
                    ? TextInput::make('mileage_out')->label(__('Mileage at handover'))->integer()->minValue($record->mileage_in ?? 0)->suffix('km')->required()
                    : null,
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->rows(2)
                    ->required($record->status->isBackStepTo($to) || $to === StockCycleStatus::Cancelled),
            ])))
            ->action(function (StockCycle $record, array $data, Action $action) use ($to): void {
                try {
                    app(TransitionStockCycle::class)($record, $to, $data['reason'] ?? null, $data);
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title(__('Status changed to ":status".', ['status' => $to->getLabel()]))->success()->send();
            });
    }

    /**
     * Record the purchase (a file in review becomes "purchased") or correct it later.
     */
    public static function recordPurchase(): Action
    {
        return Action::make('recordPurchase')
            ->label(fn (StockCycle $record): string => $record->purchase === null ? __('Record purchase') : __('Edit purchase'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color(fn (StockCycle $record): string => $record->purchase === null ? 'primary' : 'gray')
            ->visible(fn (StockCycle $record): bool => ! $record->isLocked()
                && (auth()->user()?->can($record->purchase === null ? 'create' : 'update', $record->purchase ?? Purchase::class) ?? false))
            ->modalHeading(fn (StockCycle $record): string => $record->purchase === null ? __('Record purchase') : __('Edit purchase'))
            ->modalWidth('5xl')
            ->fillForm(fn (StockCycle $record): array => $record->purchase?->attributesToArray() ?? ['mileage' => $record->mileage_in])
            ->schema(PurchaseForm::components())
            ->action(function (StockCycle $record, array $data, Action $action): void {
                try {
                    app(RecordPurchase::class)($record, $data);
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                $record->unsetRelation('purchase');
                Notification::make()->title(__('Purchase saved.'))->success()->send();
            });
    }

    /**
     * The car comes back (buy-back, leasing return): new file on the same vehicle.
     */
    public static function openNewCycle(): Action
    {
        return Action::make('openNewCycle')
            ->label(__('New file for this vehicle'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (StockCycle $record): bool => ! $record->status->isOpen()
                && (auth()->user()?->can('create', StockCycle::class) ?? false)
                && ! $record->vehicle->openStockCycle()->exists())
            ->requiresConfirmation()
            ->modalDescription(__('Use this when the car comes back, e.g. a buy-back or a leasing return. The vehicle keeps its data and history.'))
            ->action(function (StockCycle $record, Action $action): void {
                try {
                    $cycle = app(OpenStockCycle::class)($record->vehicle, ['mileage_in' => $record->mileage_out]);
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                $action->redirect(StockCycleResource::getUrl('view', ['record' => $cycle]));
            });
    }
}
