<?php

namespace App\Filament\App\Resources\BuybackObligations\Pages;

use App\Domain\Financing\Enums\BuybackStatus;
use App\Domain\Financing\Models\BuybackObligation;
use App\Filament\App\Resources\BuybackObligations\BuybackObligationResource;
use App\Support\Money;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBuybackObligations extends ListRecords
{
    protected static string $resource = BuybackObligationResource::class;

    public function getSubheading(): ?string
    {
        return __('Open obligations in total: :amount (contingent liability).', [
            'amount' => Money::format((int) BuybackObligation::query()->where('status', BuybackStatus::Open->value)->sum('amount_rp')),
        ]);
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('Open'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', BuybackStatus::Open->value)),
            'all' => Tab::make(__('All')),
        ];
    }
}
