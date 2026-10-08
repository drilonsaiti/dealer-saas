<?php

namespace App\Filament\App\Resources\StockCycles\Actions;

use App\Domain\Purchasing\Actions\RecordPurchase;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Sales\Actions\CancelSale;
use App\Domain\Sales\Actions\ContractSale;
use App\Domain\Sales\Actions\HandOverVehicle;
use App\Domain\Sales\Actions\ReserveVehicle;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Vehicles\Actions\OpenStockCycle;
use App\Domain\Vehicles\Actions\TransitionStockCycle;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\StockCycles\Schemas\PurchaseForm;
use App\Filament\App\Resources\StockCycles\Schemas\SaleForm;
use App\Filament\App\Resources\StockCycles\Schemas\StockCycleInfolist;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use App\Support\BusinessRuleException;
use Closure;
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

                $record->refresh();
                StockCycleInfolist::forget($record);
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
            ->fillForm(fn (StockCycle $record): array => $record->purchase?->attributesToArray() ?? PurchaseForm::defaults($record->mileage_in))
            ->schema(PurchaseForm::components())
            ->action(function (StockCycle $record, array $data, Action $action): void {
                try {
                    app(RecordPurchase::class)($record, $data);
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                $record->refresh();
                StockCycleInfolist::forget($record);
                Notification::make()->title(__('Purchase saved.'))->success()->send();
            });
    }

    public static function reserve(): Action
    {
        return Action::make('reserve')
            ->label(__('Reserve'))
            ->icon(Heroicon::OutlinedBookmark)
            ->color('gray')
            ->visible(fn (StockCycle $record): bool => $record->status->canTransitionTo(StockCycleStatus::Reserved)
                && $record->activeSale === null
                && (auth()->user()?->can('create', Sale::class) ?? false))
            ->modalHeading(__('Reserve for a customer'))
            ->modalWidth('6xl')
            ->fillForm(fn (StockCycle $record): array => SaleForm::fill($record, null))
            ->schema(SaleForm::components(SaleForm::MODE_RESERVE))
            ->action(fn (StockCycle $record, array $data, Action $action) => self::run($action, fn () => app(ReserveVehicle::class)($record, $data), __('Vehicle reserved.')));
    }

    public static function sell(): Action
    {
        return Action::make('sell')
            ->label(fn (StockCycle $record): string => $record->activeSale === null ? __('Sell') : __('Record sale contract'))
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->color('primary')
            ->visible(fn (StockCycle $record): bool => $record->status->canTransitionTo(StockCycleStatus::Sold)
                && ($record->activeSale === null || $record->activeSale->status === SaleStatus::Reserved)
                && (auth()->user()?->can('create', Sale::class) ?? false))
            ->modalHeading(__('Sale contract'))
            ->modalDescription(__('A trade-in car gets its own vehicle file when you save.'))
            ->modalWidth('6xl')
            ->fillForm(fn (StockCycle $record): array => SaleForm::fill($record, $record->activeSale))
            ->schema(SaleForm::components(SaleForm::MODE_CONTRACT))
            ->action(fn (StockCycle $record, array $data, Action $action) => self::run($action, fn () => app(ContractSale::class)($record, $data), __('Sale recorded.')));
    }

    public static function cancelSale(): Action
    {
        return Action::make('cancelSale')
            ->label(fn (StockCycle $record): string => $record->activeSale?->status === SaleStatus::Reserved ? __('Cancel reservation') : __('Cancel sale'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (StockCycle $record): bool => $record->activeSale !== null && (auth()->user()?->can('cancel', $record->activeSale) ?? false))
            ->requiresConfirmation()
            ->modalDescription(__('The car is for sale again. A trade-in car that has not been worked on yet is cancelled too.'))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->rows(2)->required(),
            ])
            ->action(fn (StockCycle $record, array $data, Action $action) => self::run($action, fn () => app(CancelSale::class)($record->activeSale, (string) $data['reason']), __('Cancelled.')));
    }

    public static function handOver(): Action
    {
        return Action::make('handOver')
            ->label(__('Hand over'))
            ->icon(Heroicon::OutlinedKey)
            ->color('success')
            ->visible(fn (StockCycle $record): bool => $record->activeSale !== null && (auth()->user()?->can('handOver', $record->activeSale) ?? false))
            ->modalHeading(__('Hand over to the customer'))
            ->schema(fn (StockCycle $record): array => [
                DatePicker::make('on')->label(__('Date'))->default(now())->maxDate(now())->required(),
                TextInput::make('mileage_out')->label(__('Mileage at handover'))->integer()->minValue($record->mileage_in ?? 0)->suffix('km')->required(),
            ])
            ->action(fn (StockCycle $record, array $data, Action $action) => self::run(
                $action,
                fn () => app(HandOverVehicle::class)($record->activeSale, (int) $data['mileage_out'], (string) $data['on']),
                __('Vehicle handed over.'),
            ));
    }

    /**
     * Runs a domain action; business-rule refusals become an error notification.
     *
     * @param  Closure(): mixed  $callback
     */
    private static function run(Action $action, Closure $callback, string $success): void
    {
        try {
            $callback();
        } catch (BusinessRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();

            return;
        }

        $record = $action->getRecord();

        if ($record instanceof StockCycle) {
            $record->refresh(); // other header actions and the margin depend on the new state
            StockCycleInfolist::forget($record);
        }

        Notification::make()->title($success)->success()->send();
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
