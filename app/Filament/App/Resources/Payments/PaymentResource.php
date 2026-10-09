<?php

namespace App\Filament\App\Resources\Payments;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Actions\RecordPayment;
use App\Domain\Payments\Enums\PaymentDirection;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAllocation;
use App\Domain\Purchasing\Enums\PaymentStatus;
use App\Domain\Purchasing\Models\Purchase;
use App\Filament\App\Resources\Payments\Pages\ManagePayments;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\PartySelect;
use App\Support\BusinessRuleException;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finance → Payments: every payment in or out and what it pays.
 *
 * @extends resource<Payment>
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 31;

    public static function getNavigationGroup(): string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Payments');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['party', 'allocations.allocatable']))
            ->columns([
                TextColumn::make('paid_on')->label(__('Date'))->date()->sortable(),
                TextColumn::make('direction')->label(__('Direction'))->badge()
                    ->formatStateUsing(fn (PaymentDirection $state): string => $state->getLabel())
                    ->color(fn (PaymentDirection $state): string => $state === PaymentDirection::In ? 'success' : 'warning'),
                TextColumn::make('method')->label(__('Method'))->formatStateUsing(fn (PaymentMethod $state): string => $state->getLabel()),
                TextColumn::make('party.last_name')->label(__('Contact'))->state(fn (Payment $record): ?string => $record->party?->displayName())->placeholder('–'),
                TextColumn::make('allocations')->label(__('For'))
                    ->state(fn (Payment $record): array => $record->allocations->map(fn (PaymentAllocation $a): string => match (true) {
                        $a->allocatable instanceof Invoice => $a->allocatable->type->getLabel().' '.$a->allocatable->number,
                        $a->allocatable instanceof Purchase => __('Purchase').' '.$a->allocatable->stockCycle->title(),
                        default => '–',
                    })->all())
                    ->listWithLineBreaks()->placeholder(__('Not allocated')),
                TextColumn::make('amount_rp')->label(__('Amount'))->formatStateUsing(fn (int $state): string => Money::format($state))->alignEnd()->sortable(),
                TextColumn::make('notes')->label(__('Note'))->limit(40)->placeholder('–')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('direction')->label(__('Direction'))->options(PaymentDirection::class),
                SelectFilter::make('method')->label(__('Method'))->options(PaymentMethod::class),
            ])
            ->defaultSort('paid_on', 'desc')
            ->recordActions([
                Action::make('delete')
                    ->label(__('Delete'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (Payment $record): bool => $record->bank_transaction_id === null && (auth()->user()?->can('delete', $record) ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(__('The invoices or purchases it paid are open again.'))
                    ->action(function (Payment $record): void {
                        app(RecordPayment::class)->delete($record);
                        Notification::make()->title(__('Payment deleted.'))->success()->send();
                    }),
            ]);
    }

    public static function record(): Action
    {
        return Action::make('recordPayment')
            ->label(__('Record payment'))
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn (): bool => auth()->user()?->can('create', Payment::class) ?? false)
            ->fillForm(['direction' => PaymentDirection::In->value, 'paid_on' => now()->toDateString(), 'method' => PaymentMethod::Bank->value])
            ->schema([
                Grid::make(2)->schema([
                    Select::make('direction')->label(__('Direction'))->options(PaymentDirection::class)->live()->required(),
                    DatePicker::make('paid_on')->label(__('Paid on'))->required(),
                    MoneyInput::make('amount_rp')->label(__('Amount'))->required(),
                    Select::make('method')->label(__('Method'))->options(PaymentMethod::class)->required(),
                    Select::make('invoice_id')->label(__('Invoice'))
                        ->options(fn (): array => Invoice::query()->open()->with('recipient')->orderBy('number')->get()
                            ->mapWithKeys(fn (Invoice $i): array => [$i->getKey() => $i->number.' · '.$i->recipient->displayName().' · '.__('open :amount', ['amount' => Money::format($i->openRp())])])->all())
                        ->searchable()
                        ->visible(fn (Get $get): bool => self::direction($get) === PaymentDirection::In)
                        ->columnSpan(2),
                    Select::make('purchase_id')->label(__('Purchase'))
                        ->options(fn (): array => Purchase::query()->where('payment_status', '!=', PaymentStatus::Paid->value)->with(['stockCycle.vehicle', 'seller'])->latest('contract_on')->limit(200)->get()
                            ->mapWithKeys(fn (Purchase $p): array => [$p->getKey() => $p->stockCycle->title().' · '.($p->seller?->displayName() ?? '–').' · '.Money::format($p->price_rp)])->all())
                        ->searchable()
                        ->visible(fn (Get $get): bool => self::direction($get) === PaymentDirection::Out)
                        ->columnSpan(2),
                    PartySelect::make('party_id')->label(__('Contact'))->columnSpan(2),
                    TextInput::make('notes')->label(__('Note'))->maxLength(500)->columnSpan(2),
                ]),
            ])
            ->action(function (array $data, Action $action): void {
                $target = filled($data['invoice_id'] ?? null) ? Invoice::query()->find($data['invoice_id'])
                    : (filled($data['purchase_id'] ?? null) ? Purchase::query()->find($data['purchase_id']) : null);
                $amount = (int) $data['amount_rp'];

                try {
                    app(RecordPayment::class)(
                        collect($data)->only(['direction', 'paid_on', 'amount_rp', 'method', 'party_id', 'notes'])->all(),
                        $target === null ? [] : [[$target, $target instanceof Invoice ? min($amount, $target->openRp()) : $amount]],
                    );
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();
                }

                Notification::make()->title(__('Payment booked.'))->success()->send();
            });
    }

    private static function direction(Get $get): ?PaymentDirection
    {
        $value = $get('direction');

        return $value instanceof PaymentDirection ? $value : PaymentDirection::tryFrom((string) $value);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePayments::route('/')];
    }
}
