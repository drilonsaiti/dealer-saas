<?php

namespace App\Filament\App\Resources\StockCycles\Schemas;

use App\Domain\Checklists\Actions\SyncChecklist;
use App\Domain\Checklists\Models\ChecklistItem;
use App\Domain\Documents\Actions\RequiredDocumentsChecklist;
use App\Domain\Documents\Enums\RequiredDocumentStatus;
use App\Domain\Integrations\Support\Channels;
use App\Domain\Listings\Enums\PublicationStatus;
use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Models\ListingPublication;
use App\Domain\Preparation\Models\Damage;
use App\Domain\Purchasing\Enums\VatSituation;
use App\Domain\Reporting\CalculateMargin;
use App\Domain\Reporting\Margin;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Vehicles\Enums\Code178Status;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\Financings\FinancingResource;
use App\Filament\App\Resources\Parties\PartyResource;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use App\Support\Money;
use App\Support\SwissFormat;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use WeakMap;

/**
 * The vehicle file's overview: where the car stands, what it cost, and what it is.
 */
final class StockCycleInfolist
{
    /**
     * Keyed by the record object, so it lives only as long as that object (one request),
     * also under a long-running worker (FrankenPHP).
     *
     * @var WeakMap<StockCycle, Margin>|null
     */
    private static ?WeakMap $margins = null;

    /**
     * After an action changed the file in this request, compute the margin again.
     */
    public static function forget(StockCycle $record): void
    {
        self::$margins?->offsetUnset($record);
    }

    /**
     * Computed once per record object; every margin entry reads from it.
     */
    private static function margin(StockCycle $record): Margin
    {
        self::$margins ??= new WeakMap;

        return self::$margins[$record] ??= app(CalculateMargin::class)($record);
    }

    private static function listing(StockCycle $record): ?Listing
    {
        return Listing::query()->where('stock_cycle_id', $record->getKey())->first()?->setRelation('stockCycle', $record);
    }

    private static function channelLabel(string $channel): string
    {
        return $channel === ListingPublication::WEBSITE ? __('Website') : Channels::label($channel);
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Vehicle file'))
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('status')->label(__('Status'))->badge(),
                        TextEntry::make('number')->label(__('File'))->placeholder(__('assigned on purchase'))->fontFamily('mono'),
                        TextEntry::make('file_year')->label(__('File year'))->placeholder('–'),
                        TextEntry::make('days_in_stock')
                            ->label(__('Days in stock'))
                            ->state(fn (StockCycle $record): ?int => $record->daysInStock())
                            ->placeholder('–'),
                        TextEntry::make('purchased_on')->label(__('Purchased'))->date()->placeholder('–'),
                        TextEntry::make('ready_on')->label(__('Ready for sale'))->date()->placeholder('–'),
                        TextEntry::make('listed_on')->label(__('Listed'))->date()->placeholder('–'),
                        TextEntry::make('sold_on')->label(__('Sold'))->date()->placeholder('–'),
                        TextEntry::make('planned_price_rp')->label(__('Planned price'))->formatStateUsing(fn (?int $state): string => Money::format($state))->placeholder('–'),
                        TextEntry::make('list_price_rp')->label(__('List price'))->formatStateUsing(fn (?int $state): string => Money::format($state))->placeholder('–'),
                        TextEntry::make('mileage_in')->label(__('Mileage at purchase'))->formatStateUsing(fn (?int $state): string => SwissFormat::mileage($state))->placeholder('–'),
                        TextEntry::make('mileage_out')->label(__('Mileage at handover'))->formatStateUsing(fn (?int $state): string => SwissFormat::mileage($state))->placeholder('–'),
                    ]),
                    TextEntry::make('notes')->label(__('Notes'))->placeholder('–')->columnSpanFull(),
                    TextEntry::make('trade_in_source')
                        ->label(__('Came in as trade-in'))
                        ->visible(fn (StockCycle $record): bool => $record->tradeInSource !== null)
                        ->state(fn (StockCycle $record): ?string => $record->tradeInSource?->sale->stockCycle->title())
                        ->url(fn (StockCycle $record): ?string => $record->tradeInSource === null ? null : StockCycleResource::getUrl('view', ['record' => $record->tradeInSource->sale->stockCycle]))
                        ->columnSpanFull(),
                ]),
            Section::make(fn (StockCycle $record): string => $record->activeSale?->status === SaleStatus::Reserved ? __('Reservation') : __('Sale'))
                ->visible(fn (StockCycle $record): bool => $record->activeSale !== null)
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('activeSale.buyer_party_id')
                            ->label(__('Buyer'))
                            ->state(fn (StockCycle $record): ?string => $record->activeSale?->buyer->displayName())
                            ->url(fn (StockCycle $record): ?string => $record->activeSale === null ? null : PartyResource::getUrl('edit', ['record' => $record->activeSale->buyer])),
                        TextEntry::make('activeSale.status')->label(__('Status'))->badge(),
                        TextEntry::make('activeSale.sale_on')->label(__('Contract date'))->date()->placeholder('–'),
                        TextEntry::make('activeSale.reserved_until')->label(__('Reserved until'))->date()->placeholder('–'),
                        TextEntry::make('activeSale.price_rp')->label(__('Sale price'))->formatStateUsing(fn (?int $state): string => Money::format($state)),
                        TextEntry::make('activeSale.discount_rp')->label(__('Discount'))->formatStateUsing(fn (?int $state): string => Money::format($state)),
                        TextEntry::make('sale_total')->label(__('Total incl. extras'))->state(fn (StockCycle $record): string => Money::format($record->activeSale?->totalRp())),
                        TextEntry::make('activeSale.payment_type')->label(__('Payment')),
                        TextEntry::make('activeSale.deposit_rp')->label(__('Deposit'))->formatStateUsing(fn (?int $state): string => Money::format($state)),
                        TextEntry::make('trade_in_credit')
                            ->label(__('Trade-in credit'))
                            ->state(fn (StockCycle $record): string => $record->activeSale?->tradeIn === null ? '–' : Money::format($record->activeSale->tradeIn->credited_rp).' · '.$record->activeSale->tradeIn->vehicleName())
                            ->url(fn (StockCycle $record): ?string => $record->activeSale?->tradeIn?->purchaseCycle === null ? null : StockCycleResource::getUrl('view', ['record' => $record->activeSale->tradeIn->purchaseCycle])),
                        TextEntry::make('sale_balance')->label(__('Balance to pay'))->state(fn (StockCycle $record): string => Money::format($record->activeSale?->balanceRp()))->weight('bold'),
                        TextEntry::make('activeSale.planned_handover_on')->label(__('Planned handover'))->date()->placeholder('–'),
                    ]),
                    TextEntry::make('activeSale.remarks')->label(__('Remarks'))->placeholder('–'),
                ]),
            Section::make(__('Required documents'))
                ->visible(fn (StockCycle $record): bool => app(RequiredDocumentsChecklist::class)->requiredKeys($record) !== [])
                ->collapsible()
                ->schema([
                    TextEntry::make('required_documents')
                        ->hiddenLabel()
                        ->state(fn (StockCycle $record): array => app(RequiredDocumentsChecklist::class)($record)
                            ->map(fn (array $item): string => $item['label'].': '.$item['status']->getLabel().($item['note'] ? ' – '.$item['note'] : ''))
                            ->all())
                        ->badge()
                        ->color(fn (string $state): string => match (true) {
                            str_contains($state, RequiredDocumentStatus::Missing->getLabel()) => 'danger',
                            str_contains($state, RequiredDocumentStatus::Requested->getLabel()) => 'warning',
                            str_contains($state, RequiredDocumentStatus::Present->getLabel()) => 'success',
                            default => 'gray',
                        }),
                ]),
            Section::make(__('Margin'))
                ->description(fn (StockCycle $record): string => self::margin($record)->isProvisional
                    ? __('Provisional: not every cost is confirmed yet, or the car is not sold.')
                    : __('Confirmed: all costs are confirmed.'))
                ->visible(fn (StockCycle $record): bool => $record->purchase !== null)
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('margin_revenue')
                            ->label(fn (StockCycle $record): string => match (self::margin($record)->revenueBasis) {
                                Margin::BASIS_SALE => __('Revenue'),
                                Margin::BASIS_LIST_PRICE => __('Expected revenue (list price)'),
                                Margin::BASIS_PLANNED_PRICE => __('Expected revenue (planned price)'),
                                default => __('Revenue'),
                            })
                            ->state(fn (StockCycle $record): string => self::margin($record)->revenueBasis === Margin::BASIS_NONE ? '–' : Money::format(self::margin($record)->revenueRp)),
                        TextEntry::make('margin_purchase')->label(__('Purchase price'))->state(fn (StockCycle $record): string => Money::format(self::margin($record)->purchaseRp)),
                        TextEntry::make('margin_costs')
                            ->label(__('Costs'))
                            ->state(fn (StockCycle $record): string => Money::format(self::margin($record)->costsRp()))
                            ->helperText(fn (StockCycle $record): string => __('confirmed :confirmed, open :open, promises :promises', [
                                'confirmed' => Money::format(self::margin($record)->confirmedCostsRp, false),
                                'open' => Money::format(self::margin($record)->openCostsRp, false),
                                'promises' => Money::format(self::margin($record)->openPromisesRp, false),
                            ]).(self::margin($record)->openRepairsRp > 0 ? ', '.__('approved repairs :amount', ['amount' => Money::format(self::margin($record)->openRepairsRp, false)]) : '')),
                        TextEntry::make('margin_value')
                            ->label(__('Margin'))
                            ->state(function (StockCycle $record): string {
                                $margin = self::margin($record);

                                if ($margin->marginRp() === null) {
                                    return '–';
                                }

                                return Money::format($margin->marginRp()).($margin->marginPercent() === null ? '' : " ({$margin->marginPercent()} %)");
                            })
                            ->weight('bold')
                            ->color(fn (StockCycle $record): string => (self::margin($record)->marginRp() ?? 0) < 0 ? 'danger' : 'success'),
                        TextEntry::make('margin_net_tax')
                            ->label(__('Net tax on the sale'))
                            ->visible(fn (StockCycle $record): bool => self::margin($record)->netTaxRp !== null)
                            ->state(fn (StockCycle $record): string => Money::format(self::margin($record)->netTaxRp))
                            ->helperText(fn (StockCycle $record): string => __(':rate % of the revenue (net tax rate method)', ['rate' => rtrim(rtrim((string) self::margin($record)->netTaxRate, '0'), '.')])),
                        TextEntry::make('margin_after_vat')
                            ->label(__('Margin after VAT'))
                            ->visible(fn (StockCycle $record): bool => self::margin($record)->marginAfterVatRp() !== null)
                            ->state(fn (StockCycle $record): string => Money::format(self::margin($record)->marginAfterVatRp()))
                            ->weight('bold')
                            ->color(fn (StockCycle $record): string => (self::margin($record)->marginAfterVatRp() ?? 0) < 0 ? 'danger' : 'success'),
                    ]),
                ]),
            Section::make(__('Preparation'))
                ->visible(fn (StockCycle $record): bool => $record->status->isInStock() && ! in_array($record->status, [StockCycleStatus::Listed, StockCycleStatus::Reserved], true))
                ->collapsible()
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('prep_target_on')->label(__('Ready for sale by'))->date()->placeholder('–')
                            ->color(fn (StockCycle $record): ?string => $record->prep_target_on?->isPast() && $record->status !== StockCycleStatus::ReadyForSale ? 'danger' : null),
                        TextEntry::make('open_repairs')->label(__('Open repair orders'))
                            ->state(fn (StockCycle $record): int => $record->repairOrders()->open()->count()),
                        TextEntry::make('damages_open')->label(__('Damages without repair order'))
                            ->state(fn (StockCycle $record): int => Damage::query()->where('stock_cycle_id', $record->getKey())->whereNull('repair_order_id')->count()),
                        TextEntry::make('released_for_sale_at')->label(__('Released for sale'))->dateTime()->placeholder('–'),
                    ]),
                ]),
            Section::make(__('Listing'))
                ->visible(fn (StockCycle $record): bool => self::listing($record) !== null)
                ->collapsible()
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('listing_status')->label(__('Listing'))->badge()
                            ->state(fn (StockCycle $record): ?string => self::listing($record)?->status->getLabel()),
                        TextEntry::make('listing_availability')->label(__('Shown as'))
                            ->state(fn (StockCycle $record): ?string => self::listing($record)?->availability()->getLabel()),
                        TextEntry::make('listing_channels')->label(__('Channels'))->columnSpan(2)
                            ->state(fn (StockCycle $record): array => self::listing($record)?->publications()->orderBy('channel')->get()
                                ->map(fn (ListingPublication $p): string => self::channelLabel($p->channel).': '.$p->status->getLabel()
                                    .($p->status === PublicationStatus::Failed && $p->last_error !== null ? ' – '.str($p->last_error)->limit(120) : ''))
                                ->all() ?? [])
                            ->listWithLineBreaks()
                            ->placeholder('–'),
                    ]),
                ]),
            Section::make(__('Leasing and handover'))
                ->visible(fn (StockCycle $record): bool => $record->activeSale !== null && in_array($record->activeSale->status, [SaleStatus::Contracted, SaleStatus::Invoiced, SaleStatus::Delivered], true)
                    || $record->vehicle->code178_status !== Code178Status::None)
                ->collapsible()
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('financing')->label(__('Leasing / credit'))
                            ->state(fn (StockCycle $record): ?string => ($f = $record->activeSale?->financing) === null ? null : $f->partner->displayName().' · '.$f->status->getLabel())
                            ->url(fn (StockCycle $record): ?string => ($f = $record->activeSale?->financing) === null ? null : FinancingResource::getUrl('view', ['record' => $f]))
                            ->placeholder(__('none')),
                        TextEntry::make('payout')->label(__('Expected payout'))
                            ->state(fn (StockCycle $record): ?string => ($f = $record->activeSale?->financing) === null ? null : Money::format($f->payout_expected_rp))
                            ->placeholder('–'),
                        TextEntry::make('vehicle.code178_status')->label(__('Code 178'))->badge(),
                        TextEntry::make('handover_open')->label(__('Handover'))
                            ->state(function (StockCycle $record): string {
                                $sale = $record->activeSale;

                                if ($sale === null || $sale->status === SaleStatus::Delivered) {
                                    return $record->delivered_on === null ? '–' : __('handed over on :date', ['date' => $record->delivered_on->format('d.m.Y')]);
                                }

                                $open = app(SyncChecklist::class)->handover($sale)->openRequired();

                                return $open->isEmpty() ? __('ready') : __('still open: :items', ['items' => $open->map(fn (ChecklistItem $i): string => $i->label)->implode(', ')]);
                            })
                            ->color(fn (string $state): string => $state === __('ready') ? 'success' : 'warning')
                            ->columnSpan(4),
                    ]),
                ]),
            Section::make(__('Purchase'))
                ->visible(fn (StockCycle $record): bool => $record->purchase !== null)
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('purchase.seller_party_id')
                            ->label(__('Seller'))
                            ->state(fn (StockCycle $record): ?string => $record->purchase?->seller?->displayName())
                            ->url(fn (StockCycle $record): ?string => $record->purchase?->seller === null ? null : PartyResource::getUrl('edit', ['record' => $record->purchase->seller]))
                            ->placeholder('–'),
                        TextEntry::make('purchase.seller_kind')->label(__('Seller is')),
                        TextEntry::make('purchase.purchase_type')->label(__('Purchase type')),
                        TextEntry::make('purchase.contract_on')->label(__('Purchase date'))->date(),
                        TextEntry::make('purchase.price_rp')->label(__('Purchase price'))->formatStateUsing(fn (?int $state): string => Money::format($state)),
                        TextEntry::make('purchase.vat_situation')->label(__('VAT situation'))->badge()
                            ->color(fn (VatSituation $state): string => $state === VatSituation::Unknown ? 'warning' : 'gray'),
                        TextEntry::make('purchase.vat_shown_rp')->label(__('VAT shown on the invoice'))->formatStateUsing(fn (?int $state): string => Money::format($state))->placeholder('–'),
                        TextEntry::make('purchase.payment_status')->label(__('Payment to seller'))->badge(),
                        TextEntry::make('purchase.payoff_rp')->label(__('Payoff (e.g. leasing balance)'))->formatStateUsing(fn (?int $state): string => Money::format($state))->placeholder('–'),
                        TextEntry::make('purchase.known_defects')->label(__('Known defects'))->placeholder('–')->columnSpan(2),
                        TextEntry::make('purchase.agreed_deliverables')->label(__('Agreed with the seller'))->placeholder('–'),
                    ]),
                ]),
            Section::make(__('Vehicle'))
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('vehicle.make')->label(__('Make'))->placeholder('–'),
                        TextEntry::make('vehicle.model')->label(__('Model'))->placeholder('–'),
                        TextEntry::make('vehicle.variant')->label(__('Version'))->placeholder('–'),
                        TextEntry::make('vehicle.internal_label')->label(__('Internal label'))->placeholder('–'),
                        TextEntry::make('vehicle.stammnummer')
                            ->label(__('Stammnummer'))
                            ->state(fn (StockCycle $record): ?string => $record->vehicle->formattedStammnummer())
                            ->placeholder('–')
                            ->fontFamily('mono')
                            ->copyable(),
                        TextEntry::make('vehicle.vin')->label(__('VIN'))->placeholder('–')->fontFamily('mono')->copyable(),
                        TextEntry::make('vehicle.plate')->label(__('Plate'))->placeholder('–'),
                        TextEntry::make('vehicle.type_approval')->label(__('Type approval'))->placeholder('–'),
                        TextEntry::make('vehicle.vehicle_type')->label(__('Vehicle type'))->placeholder('–'),
                        TextEntry::make('vehicle.body_type')->label(__('Body type'))->placeholder('–'),
                        TextEntry::make('vehicle.fuel')->label(__('Fuel'))->placeholder('–'),
                        TextEntry::make('vehicle.transmission')->label(__('Transmission'))->placeholder('–'),
                        TextEntry::make('vehicle.drive')->label(__('Drive'))->placeholder('–'),
                        TextEntry::make('vehicle.first_registration_on')->label(__('First registration'))->date()->placeholder('–'),
                        TextEntry::make('vehicle.power_kw')->label(__('Power (kW)'))->placeholder('–'),
                        TextEntry::make('vehicle.displacement_cc')->label(__('Displacement (cm³)'))->formatStateUsing(fn (?int $state): string => SwissFormat::number($state))->placeholder('–'),
                        TextEntry::make('vehicle.color_exterior')->label(__('Exterior colour'))->placeholder('–'),
                        TextEntry::make('vehicle.color_interior')->label(__('Interior colour'))->placeholder('–'),
                        TextEntry::make('vehicle.doors')->label(__('Doors'))->placeholder('–'),
                        TextEntry::make('vehicle.seats')->label(__('Seats'))->placeholder('–'),
                    ]),
                ]),
            Section::make(__('Registration, inspection and service'))
                ->collapsible()
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('vehicle.last_registration_on')->label(__('Last registration'))->date()->placeholder('–'),
                        TextEntry::make('vehicle.mfk_last_on')->label(__('Last MFK'))->date()->placeholder('–'),
                        TextEntry::make('vehicle.mfk_due_on')->label(__('Next MFK due'))->date()->placeholder('–'),
                        TextEntry::make('vehicle.keys_count')->label(__('Number of keys'))->placeholder('–'),
                        TextEntry::make('vehicle.service_last_on')->label(__('Last service'))->date()->placeholder('–'),
                        TextEntry::make('vehicle.service_last_km')->label(__('Last service at (km)'))->formatStateUsing(fn (?int $state): string => SwissFormat::mileage($state))->placeholder('–'),
                        TextEntry::make('vehicle.service_next_on')->label(__('Next service due'))->date()->placeholder('–'),
                        TextEntry::make('vehicle.curb_weight_kg')->label(__('Curb weight (kg)'))->formatStateUsing(fn (?int $state): string => SwissFormat::number($state))->placeholder('–'),
                    ]),
                ]),
            Section::make(__('Equipment and notes'))
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextEntry::make('vehicle.equipment')->label(__('Equipment'))->badge()->placeholder('–'),
                    TextEntry::make('vehicle.internal_notes')->label(__('Internal notes'))->placeholder('–'),
                ]),
        ]);
    }
}
