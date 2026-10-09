<?php

namespace App\Filament\App\Resources\WarrantyProducts\Pages;

use App\Filament\App\Resources\WarrantyProducts\WarrantyProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWarrantyProducts extends ManageRecords
{
    protected static string $resource = WarrantyProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(fn (array $data): array => WarrantyProductResource::fillLanguages($data)),
        ];
    }
}
