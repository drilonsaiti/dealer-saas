<?php

namespace App\Filament\App\Resources\Invoices;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLine;
use App\Domain\Invoicing\QrBill\QrReference;
use App\Domain\Parties\Enums\PartyRole;
use App\Domain\Payments\Models\PaymentAllocation;
use App\Domain\Vat\Actions\InstallDefaultVatCodes;
use App\Domain\Vat\Models\VatCode;
use App\Filament\App\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\App\Resources\Invoices\Pages\EditInvoice;
use App\Filament\App\Resources\Invoices\Pages\ListInvoices;
use App\Filament\App\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\PartySelect;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Finance → Invoices: deposit, final and standard invoices and credit notes, with QR bill.
 *
 * @extends resource<Invoice>
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Invoice');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Invoices');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Invoice'))->schema([
                Grid::make(3)->schema([
                    PartySelect::make('recipient_party_id', [PartyRole::Customer])->label(__('Invoice to'))->required()->columnSpan(2),
                    Select::make('locale')->label(__('Language'))->options((array) config('dealer.locale_names'))
                        ->default(fn (): string => (string) (Filament::getTenant()?->getAttribute('default_locale') ?? 'de'))->required(),
                    DatePicker::make('service_on')->label(__('Date of supply')),
                    DatePicker::make('due_on')->label(__('Payable by')),
                ]),
                Textarea::make('notes')->label(__('Note on the invoice'))->rows(2)->maxLength(1000),
            ]),
            Section::make(__('Lines'))
                ->description(__('Prices include VAT. The VAT is calculated with the rate valid on the invoice date.'))
                ->schema([
                    Repeater::make('lines')
                        ->hiddenLabel()
                        ->schema([
                            Grid::make(12)->schema([
                                TextInput::make('description')->label(__('Description'))->required()->maxLength(500)->columnSpan(6),
                                TextInput::make('qty')->label(__('Qty'))->numeric()->default(1)->required()->columnSpan(1),
                                MoneyInput::make('unit_price_rp')->label(__('Unit price'))->required()->columnSpan(2),
                                Select::make('vat_code_id')->label(__('VAT'))->options(fn (): array => self::vatCodeOptions())
                                    ->default(fn (): ?string => self::defaultVatCodeId())->columnSpan(3),
                            ]),
                        ])
                        ->defaultItems(1)
                        ->reorderable()
                        ->addActionLabel(__('Add line')),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (Invoice $record): string => $record->type->getLabel().' '.($record->number ?? '('.__('draft').')'))
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('status')->label(__('Status'))->badge(),
                        TextEntry::make('recipient.company_name')->label(__('Invoice to'))
                            ->state(fn (Invoice $record): string => $record->recipient->displayName()),
                        TextEntry::make('issued_on')->label(__('Date'))->date()->placeholder('–'),
                        TextEntry::make('due_on')->label(__('Payable by'))->date()->placeholder('–')
                            ->color(fn (Invoice $record): ?string => $record->isOverdue() ? 'danger' : null),
                        TextEntry::make('total_rp')->label(__('Total'))->formatStateUsing(fn (int $state): string => Money::format($state)),
                        TextEntry::make('vat_rp')->label(__('of which VAT'))->formatStateUsing(fn (int $state): string => Money::format($state)),
                        TextEntry::make('paid_rp')->label(__('Paid'))->formatStateUsing(fn (int $state, Invoice $record): string => Money::format($state + $record->credited_rp)),
                        TextEntry::make('open')->label(__('Open'))->state(fn (Invoice $record): string => Money::format($record->openRp()))->weight('bold'),
                        TextEntry::make('stockCycle.number')->label(__('Vehicle file'))
                            ->state(fn (Invoice $record): ?string => $record->stockCycle?->title())
                            ->url(fn (Invoice $record): ?string => $record->stockCycle === null ? null : StockCycleResource::getUrl('view', ['record' => $record->stockCycle]))
                            ->placeholder('–'),
                        TextEntry::make('qr_reference')->label(__('Payment reference'))->formatStateUsing(fn (?string $state): ?string => $state === null ? null : QrReference::format($state))->placeholder('–')->fontFamily('mono')->copyable(),
                        TextEntry::make('credits.number')->label(__('Corrects invoice'))->placeholder('–')->visible(fn (Invoice $record): bool => $record->credits_invoice_id !== null),
                        TextEntry::make('sent_at')->label(__('Sent'))->dateTime()->placeholder(__('not sent')),
                    ]),
                    TextEntry::make('notes')->label(__('Note on the invoice'))->placeholder('–'),
                ]),
            Section::make(__('Lines'))->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->table([
                    RepeatableEntry\TableColumn::make(__('Description')),
                    RepeatableEntry\TableColumn::make(__('Qty'))->alignment('end'),
                    RepeatableEntry\TableColumn::make(__('Unit price'))->alignment('end'),
                    RepeatableEntry\TableColumn::make(__('VAT'))->alignment('end'),
                    RepeatableEntry\TableColumn::make(__('Amount'))->alignment('end'),
                ])->schema([
                    TextEntry::make('description'),
                    TextEntry::make('qty')->formatStateUsing(fn (string $state): string => rtrim(rtrim($state, '0'), '.')),
                    TextEntry::make('unit_price_rp')->formatStateUsing(fn (int $state): string => Money::format($state, false)),
                    TextEntry::make('vat_rate')->formatStateUsing(fn (string $state, InvoiceLine $record): string => (float) $state > 0 ? rtrim(rtrim($state, '0'), '.').' %' : ($record->vatCode->label ?? '–')),
                    TextEntry::make('total_rp')->formatStateUsing(fn (int $state): string => Money::format($state, false)),
                ]),
            ]),
            Section::make(__('Payments'))
                ->visible(fn (Invoice $record): bool => $record->allocations()->exists())
                ->schema([
                    RepeatableEntry::make('allocations')->hiddenLabel()->table([
                        RepeatableEntry\TableColumn::make(__('Date')),
                        RepeatableEntry\TableColumn::make(__('Method')),
                        RepeatableEntry\TableColumn::make(__('Note')),
                        RepeatableEntry\TableColumn::make(__('Amount'))->alignment('end'),
                    ])->schema([
                        TextEntry::make('payment.paid_on')->date(),
                        TextEntry::make('payment.method')->formatStateUsing(fn (PaymentAllocation $record): string => $record->payment->method->getLabel()),
                        TextEntry::make('payment.notes')->placeholder('–'),
                        TextEntry::make('amount_rp')->formatStateUsing(fn (int $state): string => Money::format($state)),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['recipient', 'stockCycle.vehicle']))
            ->columns([
                TextColumn::make('number')->label(__('Number'))->placeholder(__('Draft'))->searchable()->sortable()->fontFamily('mono')
                    ->description(fn (Invoice $record): string => $record->type->getLabel()),
                TextColumn::make('recipient.last_name')->label(__('Invoice to'))
                    ->state(fn (Invoice $record): string => $record->recipient->displayName())
                    ->description(fn (Invoice $record): ?string => $record->stockCycle?->title())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('recipient', fn (Builder $p) => $p->whereRaw("lower(concat_ws(' ', company_name, first_name, last_name)) like ?", ['%'.mb_strtolower($search).'%']))),
                TextColumn::make('issued_on')->label(__('Date'))->date()->sortable()->placeholder('–'),
                TextColumn::make('due_on')->label(__('Payable by'))->date()->sortable()->placeholder('–')
                    ->color(fn (Invoice $record): ?string => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('total_rp')->label(__('Total'))->formatStateUsing(fn (int $state): string => Money::format($state))->alignEnd()->sortable(),
                TextColumn::make('open')->label(__('Open'))->state(fn (Invoice $record): ?string => $record->type === InvoiceType::CreditNote ? null : Money::format($record->openRp()))->alignEnd()->placeholder('–'),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Type'))->options(InvoiceType::class),
                SelectFilter::make('status')->label(__('Status'))->options(InvoiceStatus::class),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    /**
     * @return array<string, string>
     */
    public static function vatCodeOptions(): array
    {
        app(InstallDefaultVatCodes::class)();

        return VatCode::query()->where('is_active', true)->orderBy('key')->get()
            ->mapWithKeys(fn (VatCode $code): array => [$code->getKey() => $code->label])->all();
    }

    public static function defaultVatCodeId(): ?string
    {
        $key = filled(Filament::getTenant()?->getAttribute('vat_number')) ? InstallDefaultVatCodes::TAXABLE_NORMAL : InstallDefaultVatCodes::NO_TAX_SHOWN;

        return VatCode::byKey($key)->getKey();
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'qr_reference'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Invoice $record */
        return $record->type->getLabel().' '.$record->number;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoice::route('/create'),
            'view' => ViewInvoice::route('/{record}'),
            'edit' => EditInvoice::route('/{record}/edit'),
        ];
    }
}
