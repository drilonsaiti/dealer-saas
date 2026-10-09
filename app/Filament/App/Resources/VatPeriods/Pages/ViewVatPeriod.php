<?php

namespace App\Filament\App\Resources\VatPeriods\Pages;

use App\Domain\Vat\Models\VatPeriod;
use App\Filament\App\Resources\VatPeriods\VatPeriodActions;
use App\Filament\App\Resources\VatPeriods\VatPeriodResource;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewVatPeriod extends ViewRecord
{
    protected static string $resource = VatPeriodResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var VatPeriod $period */
        $period = $this->getRecord();

        return __('VAT').' '.$period->label();
    }

    protected function getHeaderActions(): array
    {
        return [
            VatPeriodActions::collect(),
            VatPeriodActions::close(),
            VatPeriodActions::export(),
            VatPeriodActions::submitted(),
            VatPeriodActions::paid(),
            ActionGroup::make([
                VatPeriodActions::download('downloadReport', __('Report (PDF)'), 'reportDocument'),
                VatPeriodActions::download('downloadDetail', __('Detail (CSV)'), 'detailDocument'),
                VatPeriodActions::download('downloadXml', __('eCH-0217 file (XML)'), 'xmlDocument'),
                VatPeriodActions::download('downloadConfirmation', __('ESTV confirmation'), 'submissionDocument'),
            ])->label(__('Files'))->icon('heroicon-o-folder')->button()->color('gray'),
        ];
    }
}
