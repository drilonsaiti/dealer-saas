<?php

namespace App\Filament\App\Resources\Parties\Pages;

use App\Filament\App\Resources\Parties\PartyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditParty extends EditRecord
{
    protected static string $resource = PartyResource::class;

    /**
     * Delete is hidden for contacts that are used in vehicle files (see PartyPolicy).
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
