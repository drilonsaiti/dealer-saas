<?php

namespace App\Filament\App\Resources\StockCycles\Actions;

use App\Domain\Financing\Actions\SaveFinancing;
use App\Domain\Financing\Enums\FinancingKind;
use App\Domain\Financing\Models\Financing;
use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Vehicles\Actions\SetCode178;
use App\Domain\Vehicles\Enums\Code178Status;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Warranty\Actions\AddWarranty;
use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Models\WarrantyProduct;
use App\Filament\App\Resources\Financings\FinancingResource;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\PartySelect;
use App\Support\BusinessRuleException;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

/**
 * Vehicle file actions for leasing / credit, warranties and code 178.
 */
final class LeasingWarrantyActions
{
    public static function financing(): Action
    {
        return Action::make('financing')
            ->label(fn (StockCycle $record): string => $record->activeSale?->financing === null ? __('Leasing / credit') : __('Edit leasing / credit'))
            ->icon(Heroicon::OutlinedBuildingLibrary)
            ->visible(fn (StockCycle $record): bool => $record->activeSale !== null
                && in_array($record->activeSale->status, [SaleStatus::Reserved, SaleStatus::Contracted, SaleStatus::Invoiced], true)
                && (auth()->user()?->can('create', Financing::class) ?? false))
            ->modalHeading(__('Leasing / credit'))
            ->modalDescription(__('The bank becomes the invoice recipient; the customer stays buyer and holder. Expected payout = cash price − first instalment you collect.'))
            ->fillForm(function (StockCycle $record): array {
                $sale = $record->activeSale;
                $financing = $sale?->financing;

                return $financing === null
                    ? ['kind' => FinancingKind::Leasing->value, 'applied_on' => now()->toDateString(), 'cash_price_rp' => $sale?->loadMissing('items')->totalRp(), 'collection_rp' => 0]
                    : $financing->only(['partner_party_id', 'kind', 'applied_on', 'cash_price_rp', 'collection_rp', 'term_months', 'km_per_year', 'residual_rp', 'nominal_rate', 'monthly_rate_rp', 'has_buyback', 'contract_number']);
            })
            ->schema([
                Grid::make(2)->schema([
                    PartySelect::make('partner_party_id', [PartyRole::FinancingPartner])->label(__('Bank'))->required(),
                    Select::make('kind')->label(__('Type'))->options(FinancingKind::class)->required(),
                    DatePicker::make('applied_on')->label(__('Applied on'))->required(),
                    TextInput::make('contract_number')->label(__('Contract number'))->maxLength(60),
                    MoneyInput::make('cash_price_rp')->label(__('Cash price'))->required()->live(onBlur: true),
                    MoneyInput::make('collection_rp')->label(__('First instalment collected by you'))->default(0)->live(onBlur: true),
                    TextInput::make('term_months')->label(__('Term'))->integer()->minValue(1)->suffix(__('months')),
                    TextInput::make('km_per_year')->label(__('km per year'))->integer()->minValue(0),
                    MoneyInput::make('residual_rp')->label(__('Residual value')),
                    MoneyInput::make('monthly_rate_rp')->label(__('Monthly rate')),
                    TextInput::make('nominal_rate')->label(__('Interest rate'))->numeric()->suffix('%'),
                    Toggle::make('has_buyback')->label(__('Buy-back obligation at lease end'))->inline(false),
                ]),
            ])
            ->action(function (StockCycle $record, array $data, Action $action): void {
                self::run($action, function () use ($record, $data): void {
                    $financing = app(SaveFinancing::class)($record->activeSale, $data);
                    Notification::make()->title(__('Leasing saved. Expected payout :amount.', ['amount' => Money::format($financing->payout_expected_rp)]))
                        ->actions([Action::make('open')->label(__('Open'))->url(FinancingResource::getUrl('view', ['record' => $financing]))])
                        ->success()->send();
                });
            });
    }

    public static function addWarranty(): Action
    {
        return Action::make('addWarranty')
            ->label(__('Add warranty'))
            ->icon(Heroicon::OutlinedShieldCheck)
            ->visible(fn (StockCycle $record): bool => $record->activeSale !== null
                && in_array($record->activeSale->status, [SaleStatus::Reserved, SaleStatus::Contracted], true)
                && (auth()->user()?->can('create', Warranty::class) ?? false))
            ->schema([
                Select::make('product_id')->label(__('Warranty product'))
                    ->options(fn (): array => WarrantyProduct::query()->where('is_active', true)->with('provider')->get()
                        ->mapWithKeys(fn (WarrantyProduct $p): array => [$p->getKey() => $p->name.' – '.($p->provider?->displayName() ?? __('own warranty'))])->all())
                    ->required()->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $product = $state === null ? null : WarrantyProduct::query()->find($state);
                        $set('price_rp', $product === null ? null : Money::toInput($product->price_rp));
                        $set('cost_rp', $product === null ? null : Money::toInput($product->cost_rp));
                    })
                    ->helperText(__('Products are set up under Settings → Warranty products.')),
                Grid::make(2)->schema([
                    MoneyInput::make('price_rp')->label(__('Price to the customer'))->required(),
                    MoneyInput::make('cost_rp')->label(__('Premium (your cost)'))->required(),
                ])->visible(fn (Get $get): bool => filled($get('product_id'))),
            ])
            ->action(function (StockCycle $record, array $data, Action $action): void {
                self::run($action, function () use ($record, $data): void {
                    app(AddWarranty::class)($record->activeSale, WarrantyProduct::query()->findOrFail($data['product_id']), ['price_rp' => $data['price_rp'], 'cost_rp' => $data['cost_rp']]);
                    Notification::make()->title(__('Warranty added: price on the sale, premium in the costs.'))->success()->send();
                });
            });
    }

    public static function code178(): Action
    {
        return Action::make('code178')
            ->label(__('Code 178'))
            ->icon(Heroicon::OutlinedLockClosed)
            ->visible(fn (): bool => auth()->user()?->can('create', Financing::class) ?? false)
            ->fillForm(fn (StockCycle $record): array => ['status' => $record->vehicle->code178_status->value, 'on' => now()->toDateString(), 'note' => $record->vehicle->code178_note])
            ->schema([
                Select::make('status')->label(__('Status'))->options(Code178Status::class)->required()
                    ->helperText(__('Entered by the leasing bank in the registration document; while entered, the car cannot be resold.')),
                DatePicker::make('on')->label(__('Date'))->required(),
                TextInput::make('note')->label(__('Note'))->maxLength(255),
            ])
            ->action(function (StockCycle $record, array $data): void {
                app(SetCode178::class)($record->vehicle, Code178Status::from($data['status']), (string) $data['on'], $data['note'] ?? null);
                Notification::make()->title(__('Saved.'))->success()->send();
            });
    }

    /**
     * @param  Closure(): void  $callback
     */
    private static function run(Action $action, Closure $callback): void
    {
        try {
            $callback();
        } catch (BusinessRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();
        }
    }
}
