<?php

namespace App\Filament\App\Resources\Sales\Pages;

use App\Filament\App\Resources\Sales\SaleResource;
use Filament\Resources\Pages\ListRecords;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;
}
