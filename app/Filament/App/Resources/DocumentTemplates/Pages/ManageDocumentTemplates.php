<?php

namespace App\Filament\App\Resources\DocumentTemplates\Pages;

use App\Domain\Documents\Actions\InstallDefaultTemplates;
use App\Filament\App\Resources\DocumentTemplates\DocumentTemplateResource;
use Filament\Resources\Pages\ManageRecords;

class ManageDocumentTemplates extends ManageRecords
{
    protected static string $resource = DocumentTemplateResource::class;

    public function mount(): void
    {
        // Dealers created before templates existed get the system version on first visit.
        app(InstallDefaultTemplates::class)();

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [DocumentTemplateResource::newVersion()];
    }
}
