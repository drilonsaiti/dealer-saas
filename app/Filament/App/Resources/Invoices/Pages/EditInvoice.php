<?php

namespace App\Filament\App\Resources\Invoices\Pages;

use App\Domain\Invoicing\Actions\SaveInvoiceDraft;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceLine;
use App\Filament\App\Resources\Invoices\InvoiceResource;
use App\Support\BusinessRuleException;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Drafts only; issued invoices cannot be edited (the policy hides this page).
 */
class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->getRecord();
        $data['lines'] = $invoice->lines()->get()->map(fn (InvoiceLine $line): array => [
            'description' => $line->description,
            'qty' => (float) $line->qty,
            'unit_price_rp' => $line->unit_price_rp,
            'vat_code_id' => $line->vat_code_id,
            'kind' => $line->kind->value,
            'source_invoice_id' => $line->source_invoice_id,
        ])->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Invoice $record */
        $lines = array_values((array) ($data['lines'] ?? []));
        unset($data['lines']);

        try {
            return app(SaveInvoiceDraft::class)($record, $data, $lines);
        } catch (BusinessRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->label(__('Delete draft'))];
    }

    protected function getRedirectUrl(): string
    {
        return InvoiceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
