<?php

namespace App\Filament\App\Resources\StockCycles\Pages;

use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\StockCycles\Actions\StockCycleActions;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The vehicle file.
 */
class ViewStockCycle extends ViewRecord
{
    protected static string $resource = StockCycleResource::class;

    public function getTitle(): string|Htmlable
    {
        $record = $this->getRecord();

        return $record instanceof StockCycle ? $record->title() : parent::getTitle();
    }

    protected function getHeaderActions(): array
    {
        return [
            StockCycleActions::changeStatus(),
            StockCycleActions::openNewCycle(),
            EditAction::make(),
        ];
    }
}
