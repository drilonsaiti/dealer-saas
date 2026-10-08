<?php

namespace App\Filament\App\Resources\StockCycles\Pages;

use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListStockCycles extends ListRecords
{
    protected static string $resource = StockCycleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New vehicle')),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'stock' => Tab::make(__('In stock'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', StockCycleStatus::inStockValues())),
            'review' => Tab::make(__('In review'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', StockCycleStatus::InReview)),
            'sold' => Tab::make(__('Sold and delivered'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [StockCycleStatus::Sold, StockCycleStatus::Delivered])),
            'archive' => Tab::make(__('Archive'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [StockCycleStatus::Archived, StockCycleStatus::Cancelled])),
            'all' => Tab::make(__('All')),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'stock';
    }
}
