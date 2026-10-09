<?php

namespace App\Filament\App\Resources\Payments\Pages;

use App\Filament\App\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePayments extends ManageRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [PaymentResource::record()];
    }
}
