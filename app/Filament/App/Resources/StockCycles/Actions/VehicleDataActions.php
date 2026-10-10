<?php

namespace App\Filament\App\Resources\StockCycles\Actions;

use App\Domain\VehicleData\Actions\FetchVehicleData;
use App\Domain\VehicleData\Support\VehicleData;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\StockCycles\Schemas\StockCycleInfolist;
use App\Support\BusinessRuleException;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * "Vehicle data": technical data, equipment and valuation from the licensed provider.
 */
final class VehicleDataActions
{
    public static function fetch(): Action
    {
        return Action::make('vehicleData')
            ->label(__('Fetch vehicle data'))
            ->icon(Heroicon::OutlinedCircleStack)
            ->visible(fn (StockCycle $record): bool => FetchVehicleData::account() !== null && (auth()->user()?->can('update', $record) ?? false))
            ->modalWidth('3xl')
            ->schema(function (StockCycle $record): array {
                try {
                    $variants = app(FetchVehicleData::class)->lookup($record->vehicle);
                } catch (BusinessRuleException $e) {
                    return [Text::make($e->getMessage())->color('danger')];
                }

                if ($variants === []) {
                    return [Text::make(__('The provider found no vehicle for type approval :type / VIN :vin.', ['type' => $record->vehicle->type_approval ?? '–', 'vin' => $record->vehicle->vin ?? '–']))];
                }

                $byId = collect($variants)->keyBy('externalId');

                return [
                    Select::make('variant')->label(__('Variant'))->required()->live()
                        ->options($byId->map(fn (VehicleData $v): string => $v->label())->all())
                        ->default(count($variants) === 1 ? $variants[0]->externalId : null),
                    CheckboxList::make('options')->label(__('Optional equipment this car has'))
                        ->options(fn (Get $get): array => ($v = $byId->get((string) $get('variant'))) === null ? [] : (array_combine($v->optionalEquipment, $v->optionalEquipment) ?: []))
                        ->visible(fn (Get $get): bool => ($byId->get((string) $get('variant')) instanceof VehicleData) && $byId->get((string) $get('variant'))->optionalEquipment !== [])
                        ->columns(2)->searchable()->bulkToggleable(),
                    Toggle::make('overwrite')->label(__('Overwrite fields that are already filled')),
                    Toggle::make('valuate')->label(__('Get a valuation for the current mileage'))->default(true),
                ];
            })
            ->modalSubmitActionLabel(__('Take over'))
            ->action(function (StockCycle $record, array $data, Action $action): void {
                if (! isset($data['variant'])) {
                    $action->halt();
                }

                try {
                    $fetch = app(FetchVehicleData::class);
                    $variant = collect($fetch->lookup($record->vehicle))->firstWhere('externalId', $data['variant']);

                    if (! $variant instanceof VehicleData) {
                        throw new BusinessRuleException(__('Choose a variant.'));
                    }

                    $fetch->apply($record->vehicle, $variant, array_values((array) ($data['options'] ?? [])), (bool) ($data['overwrite'] ?? false));
                    $message = __('Vehicle data taken over.');

                    if ($data['valuate'] ?? false) {
                        $valuation = $fetch->value($record, $variant);
                        $message .= ' '.__('Valuation: retail :retail, trade-in :trade.', [
                            'retail' => $valuation->retail_rp !== null ? Money::format($valuation->retail_rp) : '–',
                            'trade' => $valuation->trade_in_rp !== null ? Money::format($valuation->trade_in_rp) : '–',
                        ]);
                    }

                    StockCycleInfolist::forget($record);
                    Notification::make()->title($message)->success()->send();
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->persistent()->send();
                    $action->halt();
                }
            });
    }
}
