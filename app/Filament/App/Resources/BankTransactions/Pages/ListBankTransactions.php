<?php

namespace App\Filament\App\Resources\BankTransactions\Pages;

use App\Domain\Payments\Enums\MatchStatus;
use App\Filament\App\Resources\BankTransactions\BankTransactionResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBankTransactions extends ListRecords
{
    protected static string $resource = BankTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [BankTransactionResource::import()];
    }

    public function getTabs(): array
    {
        return [
            'todo' => Tab::make(__('To check'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('match_status', [MatchStatus::Proposed->value, MatchStatus::Unmatched->value])),
            'matched' => Tab::make(__('Booked'))->modifyQueryUsing(fn (Builder $query) => $query->where('match_status', MatchStatus::Matched->value)),
            'ignored' => Tab::make(__('Ignored'))->modifyQueryUsing(fn (Builder $query) => $query->where('match_status', MatchStatus::Ignored->value)),
            'all' => Tab::make(__('All')),
        ];
    }
}
