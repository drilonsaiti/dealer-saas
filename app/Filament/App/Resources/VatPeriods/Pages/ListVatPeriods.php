<?php

namespace App\Filament\App\Resources\VatPeriods\Pages;

use App\Domain\Vat\Models\VatProfile;
use App\Filament\App\Resources\VatPeriods\VatPeriodActions;
use App\Filament\App\Resources\VatPeriods\VatPeriodResource;
use App\Filament\App\Resources\VatProfiles\VatProfileResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListVatPeriods extends ListRecords
{
    protected static string $resource = VatPeriodResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return VatProfile::query()->exists()
            ? __('Periods appear with the first issued invoice. "Update" picks up invoices and payments recorded before the VAT settings existed.')
            : __('Set up the VAT settings first (Settings → VAT): method, basis, period and the approved net tax rate.');
    }

    protected function getHeaderActions(): array
    {
        return [
            VatPeriodActions::collect(),
            Action::make('settings')->label(__('VAT settings'))->color('gray')->url(fn (): string => VatProfileResource::getUrl()),
        ];
    }
}
