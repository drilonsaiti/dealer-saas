<?php

namespace App\Filament\App\Resources\Warranties\Pages;

use App\Domain\Warranty\Enums\WarrantyStatus;
use App\Domain\Warranty\Models\Warranty;
use App\Filament\App\Resources\Warranties\WarrantyResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListWarranties extends ListRecords
{
    protected static string $resource = WarrantyResource::class;

    public function getTabs(): array
    {
        return [
            'active' => Tab::make(__('Active'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', WarrantyStatus::Active->value)),
            'expiring' => Tab::make(__('Ending within 30 days'))
                ->modifyQueryUsing(fn (Builder $query) => $query->scopes(['expiringWithin' => [30]]))
                ->badge(fn (): int => Warranty::query()->expiringWithin(30)->count())
                ->badgeColor('warning'),
            'draft' => Tab::make(__('Not active yet'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', WarrantyStatus::Draft->value)),
            'all' => Tab::make(__('All')),
        ];
    }
}
