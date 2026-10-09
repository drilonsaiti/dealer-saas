<?php

namespace App\Filament\App\Resources\Invoices;

use App\Domain\Invoicing\Actions\CreateInvoiceFromSale;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\IssueInvoice;
use App\Domain\Invoicing\Actions\SendInvoice;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Actions\RecordPayment;
use App\Domain\Payments\Enums\PaymentDirection;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Models\Payment;
use App\Domain\Settings\Models\NumberSequence;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\Support\MoneyInput;
use App\Support\BusinessRuleException;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invoice actions: issue, download, send, record a payment, credit note, and from the
 * vehicle file the deposit and final invoices.
 */
final class InvoiceActions
{
    public static function issue(): Action
    {
        return Action::make('issue')
            ->label(__('Issue invoice'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->visible(fn (Invoice $record): bool => auth()->user()?->can('issue', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading(__('Issue invoice'))
            ->modalDescription(fn (Invoice $record): string => __('The invoice gets the number :number and can no longer be changed afterwards (corrections only by credit note).', [
                'number' => NumberSequence::query()->where('key', $record->type->numberSequence()->value)->first()?->preview() ?? '–',
            ]))
            ->action(function (Invoice $record, Action $action): void {
                self::run($action, fn () => app(IssueInvoice::class)($record));
                Notification::make()->title(__('Invoice :number issued.', ['number' => $record->refresh()->number]))->success()->send();
            });
    }

    public static function download(): Action
    {
        return Action::make('downloadPdf')
            ->label(__('PDF'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (Invoice $record): bool => $record->document?->currentVersion !== null)
            ->action(function (Invoice $record): StreamedResponse {
                $version = $record->document->currentVersion;

                return Storage::disk($version->disk)->download($version->path, $version->original_name);
            });
    }

    public static function send(): Action
    {
        return Action::make('send')
            ->label(__('Send by email'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->visible(fn (Invoice $record): bool => auth()->user()?->can('send', $record) ?? false)
            ->fillForm(fn (Invoice $record): array => ['email' => $record->recipient->email])
            ->schema([TextInput::make('email')->label(__('Email'))->email()->required()])
            ->modalDescription(__('The PDF is sent as attachment. Nothing is sent automatically.'))
            ->action(function (Invoice $record, array $data, Action $action): void {
                self::run($action, fn () => app(SendInvoice::class)($record, (string) $data['email']));
                Notification::make()->title(__('Sent to :email.', ['email' => $data['email']]))->success()->send();
            });
    }

    public static function recordPayment(): Action
    {
        return Action::make('recordPayment')
            ->label(__('Record payment'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (Invoice $record): bool => $record->type !== InvoiceType::CreditNote && $record->openRp() > 0
                && in_array($record->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true)
                && (auth()->user()?->can('create', Payment::class) ?? false))
            ->fillForm(fn (Invoice $record): array => ['paid_on' => now()->toDateString(), 'amount_rp' => $record->openRp(), 'method' => PaymentMethod::Bank->value])
            ->schema([
                Grid::make(3)->schema([
                    DatePicker::make('paid_on')->label(__('Paid on'))->required(),
                    MoneyInput::make('amount_rp')->label(__('Amount'))->required(),
                    Select::make('method')->label(__('Method'))->options(PaymentMethod::class)->required(),
                ]),
                TextInput::make('notes')->label(__('Note'))->maxLength(500),
            ])
            ->action(function (Invoice $record, array $data, Action $action): void {
                self::run($action, fn () => app(RecordPayment::class)([
                    'direction' => PaymentDirection::In,
                    'paid_on' => $data['paid_on'],
                    'amount_rp' => (int) $data['amount_rp'],
                    'method' => $data['method'],
                    'party_id' => $record->recipient_party_id,
                    'notes' => $data['notes'] ?? null,
                ], [[$record, min((int) $data['amount_rp'], $record->openRp())]]));
                Notification::make()->title(__('Payment booked.'))->success()->send();
            });
    }

    public static function credit(): Action
    {
        return Action::make('creditNote')
            ->label(__('Credit note'))
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('danger')
            ->visible(fn (Invoice $record): bool => $record->type !== InvoiceType::CreditNote && (auth()->user()?->can('credit', $record) ?? false))
            ->modalDescription(__('The invoice stays as it is; the credit note corrects it. Leave the amount empty to credit the whole invoice.'))
            ->schema([
                TextInput::make('reason')->label(__('Reason'))->required()->maxLength(255),
                MoneyInput::make('amount_rp')->label(__('Amount (empty = whole invoice)'))->rule('nullable'),
            ])
            ->action(function (Invoice $record, array $data, Action $action): void {
                $credit = null;
                self::run($action, function () use ($record, $data, &$credit): void {
                    $credit = app(IssueCreditNote::class)($record, (string) $data['reason'], filled($data['amount_rp'] ?? null) ? (int) $data['amount_rp'] : null);
                });
                Notification::make()->title(__('Credit note :number issued.', ['number' => $credit?->number]))->success()->send();
                $action->redirect(InvoiceResource::getUrl('view', ['record' => $credit]));
            });
    }

    /**
     * Vehicle file: deposit or final invoice from the sale (as draft to check).
     */
    public static function fromSale(InvoiceType $type): Action
    {
        return Action::make('invoice_'.$type->value)
            ->label($type === InvoiceType::Deposit ? __('Deposit invoice') : __('Final invoice'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('gray')
            ->visible(fn (StockCycle $record): bool => $record->activeSale !== null
                && ($type !== InvoiceType::Deposit || $record->activeSale->deposit_rp > 0)
                && (auth()->user()?->can('create', Invoice::class) ?? false))
            ->action(function (StockCycle $record, Action $action) use ($type): void {
                $invoice = null;
                self::run($action, function () use ($record, $type, &$invoice): void {
                    $invoice = app(CreateInvoiceFromSale::class)($record->activeSale, $type);
                });
                Notification::make()->title(__('Draft created. Check it and issue it.'))->success()->send();
                $action->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
            });
    }

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
