<?php

namespace App\Filament\App\Resources\IntegrationAccounts\Pages;

use App\Filament\App\Resources\IntegrationAccounts\IntegrationAccountResource;
use App\Filament\App\Resources\IntegrationAccounts\IntegrationActions;
use Filament\Resources\Pages\ViewRecord;

class ViewIntegrationAccount extends ViewRecord
{
    protected static string $resource = IntegrationAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            IntegrationActions::edit(),
            IntegrationActions::test(),
            IntegrationActions::syncAll(),
            IntegrationActions::import(),
        ];
    }
}
