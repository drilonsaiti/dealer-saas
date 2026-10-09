<?php

namespace App\Filament\App\Resources\VatProfiles\Pages;

use App\Filament\App\Resources\VatProfiles\VatProfileResource;
use Filament\Resources\Pages\ManageRecords;

class ManageVatProfiles extends ManageRecords
{
    protected static string $resource = VatProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [VatProfileResource::create()];
    }
}
