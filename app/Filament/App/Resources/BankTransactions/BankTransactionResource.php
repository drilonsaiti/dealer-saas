<?php

namespace App\Filament\App\Resources\BankTransactions;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Actions\ImportBankStatement;
use App\Domain\Payments\Actions\MatchBankTransaction;
use App\Domain\Payments\Enums\MatchStatus;
use App\Domain\Payments\Models\BankTransaction;
use App\Domain\Settings\Models\BankAccount;
use App\Filament\App\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Filament\App\Resources\Invoices\InvoiceResource;
use App\Support\BusinessRuleException;
use App\Support\Money;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Finance → Bank: camt.053/054 import; QR payments are booked automatically, the rest is
 * confirmed, assigned or ignored here.
 *
 * @extends resource<BankTransaction>
 */
class BankTransactionResource extends Resource
{
    protected static ?string $model = BankTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 32;

    protected static ?string $slug = 'bank';

    public static function getNavigationGroup(): string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Bank booking');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Bank bookings');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = BankTransaction::query()->whereIn('match_status', [MatchStatus::Proposed->value, MatchStatus::Unmatched->value])->where('amount_rp', '>', 0)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['proposedInvoice.recipient', 'payment.allocations.allocatable', 'bankAccount']))
            ->columns([
                TextColumn::make('booked_on')->label(__('Booked'))->date()->sortable(),
                TextColumn::make('amount_rp')->label(__('Amount'))->formatStateUsing(fn (int $state): string => Money::format($state))->alignEnd()
                    ->color(fn (int $state): string => $state < 0 ? 'danger' : 'success')->sortable(),
                TextColumn::make('counterparty')->label(__('From / to'))->placeholder('–')->searchable()
                    ->description(fn (BankTransaction $record): ?string => $record->remittance),
                TextColumn::make('reference')->label(__('Reference'))->placeholder('–')->fontFamily('mono')->searchable()->toggleable(),
                TextColumn::make('match_status')->label(__('Status'))->badge(),
                TextColumn::make('assigned')->label(__('Invoice'))
                    ->state(fn (BankTransaction $record): ?string => match (true) {
                        $record->payment !== null => $record->payment->allocations->first()?->allocatable instanceof Invoice ? $record->payment->allocations->first()->allocatable->number : null,
                        $record->proposedInvoice !== null => $record->proposedInvoice->number.' · '.$record->proposedInvoice->recipient->displayName().' ?',
                        default => null,
                    })
                    ->placeholder('–'),
                TextColumn::make('bankAccount.label')->label(__('Account'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('booked_on', 'desc')
            ->recordActions([
                Action::make('confirm')
                    ->label(__('Confirm'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (BankTransaction $record): bool => $record->match_status === MatchStatus::Proposed && self::canManage())
                    ->action(fn (BankTransaction $record, Action $action) => self::run($action, fn () => app(MatchBankTransaction::class)->book($record, $record->proposedInvoice), __('Payment booked.'))),
                ActionGroup::make([
                    Action::make('assign')
                        ->label(__('Assign to invoice'))
                        ->icon(Heroicon::OutlinedLink)
                        ->visible(fn (BankTransaction $record): bool => $record->payment_id === null && $record->isCredit() && self::canManage())
                        ->schema([
                            Select::make('invoice_id')->label(__('Invoice'))->required()->searchable()
                                ->options(fn (): array => Invoice::query()->open()->with('recipient')->orderBy('number')->get()
                                    ->mapWithKeys(fn (Invoice $i): array => [$i->getKey() => $i->number.' · '.$i->recipient->displayName().' · '.__('open :amount', ['amount' => Money::format($i->openRp())])])->all()),
                        ])
                        ->action(fn (BankTransaction $record, array $data, Action $action) => self::run($action, fn () => app(MatchBankTransaction::class)->book($record, Invoice::query()->findOrFail($data['invoice_id'])), __('Payment booked.'))),
                    Action::make('ignore')
                        ->label(__('Ignore'))
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->visible(fn (BankTransaction $record): bool => $record->payment_id === null && $record->match_status !== MatchStatus::Ignored && self::canManage())
                        ->action(fn (BankTransaction $record, Action $action) => self::run($action, fn () => app(MatchBankTransaction::class)->ignore($record), __('Ignored.'))),
                    Action::make('unassign')
                        ->label(__('Undo assignment'))
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('danger')
                        ->visible(fn (BankTransaction $record): bool => $record->payment_id !== null && self::canManage())
                        ->requiresConfirmation()
                        ->modalDescription(__('The payment is removed and the invoice is open again.'))
                        ->action(fn (BankTransaction $record, Action $action) => self::run($action, fn () => app(MatchBankTransaction::class)->unassign($record), __('Assignment removed.'))),
                    Action::make('openInvoice')
                        ->label(__('Open invoice'))
                        ->icon(Heroicon::OutlinedDocumentText)
                        ->visible(fn (BankTransaction $record): bool => $record->payment?->allocations->first()?->allocatable instanceof Invoice)
                        ->url(fn (BankTransaction $record): ?string => ($invoice = $record->payment?->allocations->first()?->allocatable) instanceof Invoice ? InvoiceResource::getUrl('view', ['record' => $invoice]) : null),
                ]),
            ]);
    }

    public static function import(): Action
    {
        return Action::make('importStatement')
            ->label(__('Import bank statement'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn (): bool => self::canManage())
            ->modalDescription(__('camt.053 (statement) or camt.054 (notification) file from e-banking. Bookings already imported are skipped.'))
            ->fillForm(fn (): array => ['bank_account_id' => BankAccount::query()->orderByDesc('is_default')->value('id')])
            ->schema([
                Select::make('bank_account_id')->label(__('Bank account'))->options(fn (): array => BankAccount::query()->pluck('label', 'id')->all())->required(),
                FileUpload::make('file')->label(__('File'))->storeFiles(false)->acceptedFileTypes(['text/xml', 'application/xml'])->maxSize(20480)->required(),
            ])
            ->action(function (array $data, Action $action): void {
                $file = $data['file'];

                if (! $file instanceof TemporaryUploadedFile) {
                    return;
                }

                try {
                    $summary = app(ImportBankStatement::class)(BankAccount::query()->findOrFail($data['bank_account_id']), $file->getRealPath(), $file->getClientOriginalName());
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title(__(':new new bookings: :matched booked automatically, :proposed to confirm, :open to assign.', $summary))
                    ->body($summary['skipped'] > 0 ? __(':skipped were already imported.', $summary) : null)
                    ->success()
                    ->send();
            });
    }

    private static function canManage(): bool
    {
        return auth()->user()?->can('create', BankTransaction::class) ?? false;
    }

    private static function run(Action $action, Closure $callback, string $success): void
    {
        try {
            $callback();
        } catch (BusinessRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();

            return;
        }

        Notification::make()->title($success)->success()->send();
    }

    public static function getPages(): array
    {
        return ['index' => ListBankTransactions::route('/')];
    }
}
