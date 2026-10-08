<?php

namespace App\Filament\App\Resources\Documents\Pages;

use App\Filament\App\Resources\Documents\DocumentResource;
use Filament\Resources\Pages\ListRecords;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;
}
