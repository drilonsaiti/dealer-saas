<?php

namespace App\Filament\App\Resources\Invoices\Pages;

use App\Domain\Invoicing\Actions\SaveInvoiceDraft;
use App\Domain\Invoicing\Enums\InvoiceType;
use App\Filament\App\Resources\Invoices\InvoiceResource;
use App\Support\BusinessRuleException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

/**
 * Standard invoice (parts, services, workshop) as draft.
 */
class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $lines = array_values((array) ($data['lines'] ?? []));
        unset($data['lines']);

        try {
            return app(SaveInvoiceDraft::class)(null, [...$data, 'type' => InvoiceType::Standard], $lines);
        } catch (BusinessRuleException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return InvoiceResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
