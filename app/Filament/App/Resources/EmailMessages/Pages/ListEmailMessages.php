<?php

namespace App\Filament\App\Resources\EmailMessages\Pages;

use App\Filament\App\Resources\EmailMessages\EmailMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListEmailMessages extends ListRecords
{
    protected static string $resource = EmailMessageResource::class;
}
