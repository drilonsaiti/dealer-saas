<?php

namespace App\Filament\App\Resources\Parties\Pages;

use App\Filament\App\Resources\Parties\PartyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateParty extends CreateRecord
{
    protected static string $resource = PartyResource::class;

    protected function getRedirectUrl(): string
    {
        return PartyResource::getUrl('index');
    }
}
