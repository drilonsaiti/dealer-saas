<?php

namespace App\Filament\App\Resources\Invoices\Pages;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Filament\App\Resources\Invoices\InvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New invoice'))];
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('Open'))
                ->modifyQueryUsing(fn (Builder $query) => $query->scopes('open'))
                ->badge(fn (): int => Invoice::query()->open()->count()),
            'overdue' => Tab::make(__('Overdue'))
                ->modifyQueryUsing(fn (Builder $query) => $query->scopes('open')->whereDate('due_on', '<', today()))
                ->badge(fn (): int => Invoice::query()->open()->whereDate('due_on', '<', today())->count())
                ->badgeColor('danger'),
            'drafts' => Tab::make(__('Drafts'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', InvoiceStatus::Draft->value)),
            'all' => Tab::make(__('All')),
        ];
    }
}
