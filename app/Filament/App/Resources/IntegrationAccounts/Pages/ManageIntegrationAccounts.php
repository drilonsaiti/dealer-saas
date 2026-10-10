<?php

namespace App\Filament\App\Resources\IntegrationAccounts\Pages;

use App\Filament\App\Resources\IntegrationAccounts\IntegrationAccountResource;
use App\Filament\App\Resources\IntegrationAccounts\IntegrationActions;
use Filament\Resources\Pages\ManageRecords;

class ManageIntegrationAccounts extends ManageRecords
{
    protected static string $resource = IntegrationAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [IntegrationActions::connect()];
    }
}
