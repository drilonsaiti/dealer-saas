<?php

namespace App\Filament\App\Resources\Invoices\Pages;

use App\Domain\Invoicing\Models\Invoice;
use App\Filament\App\Resources\Invoices\InvoiceActions;
use App\Filament\App\Resources\Invoices\InvoiceResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Invoice $invoice */
        $invoice = $this->getRecord();

        return $invoice->type->getLabel().' '.($invoice->number ?? '('.__('draft').')');
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label(__('Edit draft')),
            InvoiceActions::issue(),
            InvoiceActions::recordPayment(),
            InvoiceActions::download(),
            ActionGroup::make([
                InvoiceActions::send(),
                InvoiceActions::credit(),
            ])->label(__('More'))->button()->color('gray'),
        ];
    }
}
