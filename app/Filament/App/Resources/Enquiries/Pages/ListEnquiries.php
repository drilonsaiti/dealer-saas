<?php

namespace App\Filament\App\Resources\Enquiries\Pages;

use App\Domain\Listings\Enums\EnquiryStatus;
use App\Domain\Listings\Models\Enquiry;
use App\Filament\App\Resources\Enquiries\EnquiryResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEnquiries extends ListRecords
{
    protected static string $resource = EnquiryResource::class;

    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('Open'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', '!=', EnquiryStatus::Closed->value))
                ->badge(fn (): int => Enquiry::query()->where('status', EnquiryStatus::New->value)->count()),
            'all' => Tab::make(__('All')),
        ];
    }
}
