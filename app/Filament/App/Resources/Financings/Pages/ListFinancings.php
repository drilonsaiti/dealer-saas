<?php

namespace App\Filament\App\Resources\Financings\Pages;

use App\Domain\Financing\Models\Financing;
use App\Filament\App\Resources\Financings\FinancingResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListFinancings extends ListRecords
{
    protected static string $resource = FinancingResource::class;

    public function getSubheading(): ?string
    {
        return __('A leasing is started from the vehicle file (More → Leasing / credit).');
    }

    public function getTabs(): array
    {
        return [
            'payout' => Tab::make(__('Payout outstanding'))
                ->modifyQueryUsing(fn (Builder $query) => $query->scopes('awaitingPayout'))
                ->badge(fn (): int => Financing::query()->awaitingPayout()->count()),
            'active' => Tab::make(__('Open'))
                ->modifyQueryUsing(fn (Builder $query) => $query->scopes('active')->where('status', '!=', 'paid_out')),
            'all' => Tab::make(__('All')),
        ];
    }
}
