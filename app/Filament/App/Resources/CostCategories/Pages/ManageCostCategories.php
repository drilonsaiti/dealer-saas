<?php

namespace App\Filament\App\Resources\CostCategories\Pages;

use App\Filament\App\Resources\CostCategories\CostCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCostCategories extends ManageRecords
{
    protected static string $resource = CostCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(fn (array $data): array => CostCategoryResource::withKey($data)),
        ];
    }
}
