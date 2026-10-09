<?php

namespace App\Filament\App\Resources\ChecklistTemplates\Pages;

use App\Domain\Checklists\Actions\InstallDefaultChecklists;
use App\Filament\App\Resources\ChecklistTemplates\ChecklistTemplateResource;
use Filament\Resources\Pages\ManageRecords;

class ManageChecklistTemplates extends ManageRecords
{
    protected static string $resource = ChecklistTemplateResource::class;

    public function mount(): void
    {
        app(InstallDefaultChecklists::class)();
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [ChecklistTemplateResource::create()];
    }
}
