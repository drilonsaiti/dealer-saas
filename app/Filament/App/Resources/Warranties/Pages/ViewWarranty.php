<?php

namespace App\Filament\App\Resources\Warranties\Pages;

use App\Filament\App\Resources\Warranties\WarrantyResource;
use Filament\Resources\Pages\ViewRecord;

class ViewWarranty extends ViewRecord
{
    protected static string $resource = WarrantyResource::class;

    protected function getHeaderActions(): array
    {
        return [WarrantyResource::register(), WarrantyResource::remove()];
    }
}
