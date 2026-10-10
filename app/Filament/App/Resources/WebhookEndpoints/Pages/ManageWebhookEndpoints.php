<?php

namespace App\Filament\App\Resources\WebhookEndpoints\Pages;

use App\Filament\App\Resources\WebhookEndpoints\WebhookEndpointResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWebhookEndpoints extends ManageRecords
{
    protected static string $resource = WebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->mutateDataUsing(fn (array $data): array => WebhookEndpointResource::withSecret($data))];
    }
}
