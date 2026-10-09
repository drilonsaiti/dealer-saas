<?php

namespace App\Filament\App\Resources\StockCycles\Pages;

use App\Domain\Invoicing\Enums\InvoiceType;
use App\Domain\Vehicles\Models\StockCycle;
use App\Filament\App\Resources\Invoices\InvoiceActions;
use App\Filament\App\Resources\StockCycles\Actions\ContractActions;
use App\Filament\App\Resources\StockCycles\Actions\StockCycleActions;
use App\Filament\App\Resources\StockCycles\StockCycleResource;
use Filament\Actions\ActionGroup;
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
            StockCycleActions::recordPurchase(),
            StockCycleActions::reserve(),
            StockCycleActions::sell(),
            StockCycleActions::handOver(),
            StockCycleActions::cancelSale(),
            StockCycleActions::changeStatus(),
            ActionGroup::make([
                ContractActions::salesContract(),
                ContractActions::purchaseContract(),
                InvoiceActions::fromSale(InvoiceType::Deposit),
                InvoiceActions::fromSale(InvoiceType::Final),
                EditAction::make(),
                StockCycleActions::documentChecklist(),
                StockCycleActions::export(),
                StockCycleActions::openNewCycle(),
            ])->label(__('More'))->button()->color('gray'),
        ];
    }
}
