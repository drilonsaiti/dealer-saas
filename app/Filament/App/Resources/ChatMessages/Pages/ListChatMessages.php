<?php

namespace App\Filament\App\Resources\ChatMessages\Pages;

use App\Filament\App\Resources\ChatMessages\ChatMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListChatMessages extends ListRecords
{
    protected static string $resource = ChatMessageResource::class;
}
