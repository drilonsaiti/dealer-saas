<?php

namespace App\Filament\App\Resources\StockCycles\Schemas;

use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Parties\Models\Party;
use App\Domain\Sales\Actions\ReserveVehicle;
use App\Domain\Sales\Enums\PaymentType;
use App\Domain\Sales\Enums\SaleItemKind;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\PartySelect;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Sale terms for "Reserve" and "Sell": customer, price, extras and trade-in.
 */
final class SaleForm
{
    public const MODE_RESERVE = 'reserve';

    public const MODE_CONTRACT = 'contract';

    /**
     * @return list<mixed>
     */
    public static function components(string $mode): array
    {
        return [
            Section::make(__('Customer and price'))
                ->schema([
                    Grid::make(3)->schema([
                        PartySelect::make('buyer_party_id', [PartyRole::Customer])
                            ->label(__('Buyer'))
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                $locale = $state === null ? null : Party::query()->whereKey($state)->value('locale');

                                if ($locale !== null) {
                                    $set('locale', $locale);
                                }
                            })
                            ->columnSpan(2),
                        Select::make('payment_type')->label(__('Payment'))->options(PaymentType::class)->default(PaymentType::Bank->value)->live()->required(),
                        MoneyInput::make('price_rp')->label(__('Sale price'))->required(),
                        MoneyInput::make('discount_rp')->label(__('Discount'))->default(0),
                        MoneyInput::make('deposit_rp')->label(__('Deposit'))->default(0),
                        $mode === self::MODE_RESERVE
                            ? DatePicker::make('reserved_until')->label(__('Reserved until'))->default(now()->addDays(7))->minDate(now())->required()
                            : DatePicker::make('sale_on')->label(__('Contract date'))->default(now())->maxDate(now()->addDay())->required(),
                        DatePicker::make('planned_handover_on')->label(__('Planned handover')),
                        Select::make('locale')->label(__('Contract language'))->options(config('dealer.locale_names'))->default('de')->required(),
                        PartySelect::make('invoice_recipient_party_id', [PartyRole::FinancingPartner])
                            ->label(__('Invoice to (leasing / financing partner)'))
                            ->visible(fn (Get $get): bool => in_array($get('payment_type'), [PaymentType::Leasing, PaymentType::Leasing->value, PaymentType::Credit, PaymentType::Credit->value], true))
                            ->columnSpan(2),
                        PartySelect::make('holder_party_id', [PartyRole::Customer])
                            ->label(__('Vehicle holder, if not the buyer')),
                    ]),
                    Textarea::make('remarks')->label(__('Remarks'))->rows(2),
                ]),
            Section::make(__('Extras sold with the car'))
                ->collapsible()
                ->schema([
                    Repeater::make('items')
                        ->hiddenLabel()
                        ->schema([
                            Select::make('kind')->label(__('Type'))->options(SaleItemKind::class)->default(SaleItemKind::Accessory->value)->required(),
                            TextInput::make('description')->label(__('Description'))->required()->maxLength(255)->columnSpan(2),
                            TextInput::make('qty')->label(__('Quantity'))->numeric()->default(1)->minValue(0.01)->required(),
                            MoneyInput::make('unit_price_rp')->label(__('Unit price'))->required(),
                        ])
                        ->columns(5)
                        ->defaultItems(0)
                        ->addActionLabel(__('Add extra')),
                ]),
            Section::make(__('Trade-in'))
                ->collapsible()
                ->schema([
                    Toggle::make('has_trade_in')->label(__('The customer trades in a car'))->live(),
                    Grid::make(4)
                        ->visible(fn (Get $get): bool => (bool) $get('has_trade_in'))
                        ->disabled(fn (?StockCycle $record): bool => $record?->activeSale?->tradeIn?->isConfirmed() ?? false)
                        ->schema([
                            TextInput::make('trade_in.vehicle.stammnummer')->label(__('Stammnummer'))->placeholder('683.737.537'),
                            TextInput::make('trade_in.vehicle.vin')->label(__('VIN'))->maxLength(20),
                            TextInput::make('trade_in.vehicle.make')->label(__('Make'))->required(fn (Get $get): bool => (bool) $get('has_trade_in')),
                            TextInput::make('trade_in.vehicle.model')->label(__('Model')),
                            DatePicker::make('trade_in.vehicle.first_registration_on')->label(__('First registration')),
                            TextInput::make('trade_in.mileage')->label(__('Mileage'))->integer()->minValue(0)->suffix('km'),
                            MoneyInput::make('trade_in.value_rp')->label(__('Trade-in value'))->required(fn (Get $get): bool => (bool) $get('has_trade_in')),
                            MoneyInput::make('trade_in.payoff_rp')->label(__('Payoff (e.g. leasing balance)'))->default(0),
                            PartySelect::make('trade_in.payoff_party_id', [PartyRole::FinancingPartner])->label(__('Paid off to')),
                            MoneyInput::make('trade_in.customer_payout_rp')->label(__('Paid out to customer'))->default(0),
                            MoneyInput::make('trade_in.customer_topup_rp')->label(__('Customer tops up'))->default(0),
                            Textarea::make('trade_in.condition_notes')->label(__('Condition'))->rows(1)->columnSpan(4),
                        ]),
                ]),
        ];
    }

    /**
     * Form state for an existing sale (e.g. contracting a reservation) or a new one.
     *
     * @return array<string, mixed>
     */
    public static function fill(StockCycle $cycle, ?Sale $sale): array
    {
        if ($sale === null) {
            // fillForm() replaces field defaults, so the defaults live here.
            return [
                'payment_type' => PaymentType::Bank->value,
                'sale_on' => now()->toDateString(),
                'reserved_until' => now()->addDays(ReserveVehicle::DEFAULT_RESERVATION_DAYS)->toDateString(),
                'locale' => app(TenantContext::class)->tenant()->default_locale ?? 'de',
                'price_rp' => $cycle->list_price_rp,
                'discount_rp' => 0,
                'deposit_rp' => 0,
                'items' => [],
                'has_trade_in' => false,
            ];
        }

        $tradeIn = $sale->tradeIn;

        return [
            ...$sale->attributesToArray(),
            'sale_on' => $sale->sale_on?->toDateString() ?? now()->toDateString(),
            'items' => $sale->items->map(fn (SaleItem $item): array => [
                'kind' => $item->kind->value,
                'description' => $item->description,
                'qty' => $item->qty,
                'unit_price_rp' => $item->unit_price_rp,
            ])->all(),
            'has_trade_in' => $tradeIn !== null,
            'trade_in' => $tradeIn === null ? null : [
                ...$tradeIn->attributesToArray(),
                'vehicle' => $tradeIn->vehicle_data,
            ],
        ];
    }
}
