<?php

namespace App\Filament\Platform\Resources\RestoreDrills\Pages;

use App\Filament\Platform\Resources\RestoreDrills\RestoreDrillResource;
use App\Filament\Platform\Widgets\RestoreDrillStatus;
use Filament\Resources\Pages\ListRecords;

class ListRestoreDrills extends ListRecords
{
    protected static string $resource = RestoreDrillResource::class;

    protected function getHeaderWidgets(): array
    {
        return [RestoreDrillStatus::class];
    }
}
