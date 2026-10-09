<?php

namespace App\Filament\App\Resources\Financings\Pages;

use App\Domain\Financing\Actions\ChangeFinancingStatus;
use App\Domain\Financing\Enums\FinancingStatus;
use App\Domain\Financing\Models\Financing;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Invoicing\Models\Invoice;
use App\Filament\App\Resources\Financings\FinancingResource;
use App\Filament\App\Resources\Invoices\InvoiceResource;
use App\Filament\Support\MoneyInput;
use App\Support\BusinessRuleException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewFinancing extends ViewRecord
{
    protected static string $resource = FinancingResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Financing $financing */
        $financing = $this->getRecord();

        return $financing->kind->getLabel().' – '.$financing->sale->stockCycle->title();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->step('approve', FinancingStatus::Approved, __('Approved'), Heroicon::OutlinedHandThumbUp),
            $this->step('contractReceived', FinancingStatus::ContractReceived, __('Contract received'), Heroicon::OutlinedDocumentArrowDown)
                ->modalDescription(__('The customer can revoke for 14 days from today. With a buy-back clause, the obligation is recorded with a reminder 3 months before lease end.'))
                ->fillForm(fn (Financing $record): array => [
                    'received_on' => now()->toDateString(),
                    ...$record->only(['contract_number', 'term_months', 'km_per_year', 'residual_rp', 'monthly_rate_rp', 'has_buyback']),
                ])
                ->schema([
                    Grid::make(2)->schema([
                        DatePicker::make('received_on')->label(__('Received on'))->required(),
                        TextInput::make('contract_number')->label(__('Contract number'))->required()->maxLength(60),
                        TextInput::make('term_months')->label(__('Term'))->integer()->minValue(1)->suffix(__('months')),
                        TextInput::make('km_per_year')->label(__('km per year'))->integer(),
                        MoneyInput::make('residual_rp')->label(__('Residual value')),
                        MoneyInput::make('monthly_rate_rp')->label(__('Monthly rate')),
                        Toggle::make('has_buyback')->label(__('Buy-back obligation at lease end'))->inline(false),
                    ]),
                ]),
            $this->step('signed', FinancingStatus::Signed, __('Signed by the customer'), Heroicon::OutlinedPencilSquare),
            $this->step('documentsSent', FinancingStatus::DocumentsSent, __('Documents sent to the bank'), Heroicon::OutlinedPaperAirplane)
                ->modalDescription(__('Only with a complete checklist. The payout is expected within 10 days.')),
            Action::make('invoice')
                ->label(__('Invoice to the bank'))
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->url(function (Financing $record): ?string {
                    $invoice = Invoice::query()->where('sale_id', $record->sale_id)->where('recipient_party_id', $record->partner_party_id)
                        ->where('type', InvoiceType::Final->value)->latest()->first();

                    return $invoice === null ? null : InvoiceResource::getUrl('view', ['record' => $invoice]);
                })
                ->visible(fn (Financing $record): bool => Invoice::query()->where('sale_id', $record->sale_id)->where('recipient_party_id', $record->partner_party_id)->exists()),
            ActionGroup::make([
                $this->step('reject', FinancingStatus::Rejected, __('Rejected by the bank'), Heroicon::OutlinedXCircle)->color('danger'),
                $this->step('cancel', FinancingStatus::Cancelled, __('Cancel financing'), Heroicon::OutlinedXMark)->color('danger'),
            ])->label(__('More'))->button()->color('gray'),
        ];
    }

    private function step(string $name, FinancingStatus $to, string $label, Heroicon $icon): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->visible(fn (Financing $record): bool => in_array($to, $record->status->next(), true) && (auth()->user()?->can('update', $record) ?? false))
            ->requiresConfirmation()
            ->action(function (Financing $record, array $data, Action $action) use ($to): void {
                try {
                    app(ChangeFinancingStatus::class)($record, $to, $data);
                } catch (BusinessRuleException $e) {
                    Notification::make()->title($e->getMessage())->danger()->persistent()->send();
                    $action->halt();
                }

                $record->refresh();
                Notification::make()->title(__('Status: :status', ['status' => $to->getLabel()]))->success()->send();
            });
    }
}
