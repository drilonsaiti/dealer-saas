<?php

namespace App\Filament\App\Resources\StockCycles\Actions;

use App\Domain\Pricing\Actions\SuggestPrice;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\StockCycles\Schemas\StockCycleInfolist;
use App\Filament\Support\MoneyInput;
use App\Support\BusinessRuleException;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

final class PricingActions
{
    public static function apply(): Action
    {
        return Action::make('applyPrice')
            ->label(__('Apply price suggestion'))
            ->icon(Heroicon::OutlinedArrowTrendingDown)
            ->visible(fn (StockCycle $record): bool => (app(SuggestPrice::class)($record)?->lowersPrice() ?? false) && (auth()->user()?->can('update', $record) ?? false))
            ->fillForm(fn (StockCycle $record): array => ['price_rp' => app(SuggestPrice::class)($record)?->suggestedRp])
            ->modalDescription(fn (StockCycle $record): string => __('Now :current. The new price goes to the advert and the portals.', ['current' => Money::format($record->list_price_rp)]))
            ->schema([MoneyInput::make('price_rp')->label(__('New price'))->required()])
            ->action(function (StockCycle $record, array $data, Action $action): void {
                try {
                    app(SuggestPrice::class)->apply($record, (int) $data['price_rp']);
                    StockCycleInfolist::forget($record);
                    Notification::make()->title(__('Price changed to :price.', ['price' => Money::format((int) $data['price_rp'])]))->success()->send();
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }
            });
    }
}
