<?php

namespace App\Domain\Vat\Actions;

use App\Domain\Vat\Enums\VatPeriodStatus;
use App\Domain\Vat\Models\VatPeriod;
use App\Domain\Vat\Support\PeriodCalculator;
use App\Domain\Vat\Support\VatDocuments;
use App\Domain\Vat\Support\VatReport;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Releases a complete return (permission vat.close): the figures are frozen and never
 * recalculated, the PDF report and the CSV detail are filed. Entries of a closed period can
 * no longer change (also enforced by a database trigger); later changes go into a correction.
 */
class CloseVatPeriod
{
    public function __construct(
        private readonly PeriodCalculator $calculator,
        private readonly VatReport $report,
        private readonly VatDocuments $documents,
    ) {}

    public function __invoke(VatPeriod $period): VatPeriod
    {
        return DB::transaction(function () use ($period): VatPeriod {
            $period = VatPeriod::query()->lockForUpdate()->findOrFail($period->getKey());

            if ($period->status !== VatPeriodStatus::Open) {
                throw new BusinessRuleException(__('This VAT period is already closed.'));
            }

            $figures = ($this->calculator)($period);

            if (! $figures['complete']) {
                throw BusinessRuleException::because(array_map(
                    fn (array $check): string => (string) __($check['text'], $check['params']),
                    $figures['checks'],
                ));
            }

            $period->forceFill([
                'status' => VatPeriodStatus::Closed,
                'figures' => $figures,
                'closed_by' => Auth::id(),
                'closed_at' => now(),
            ])->save();

            $name = 'MWST_'.$period->starts_on->format('Y-m-d').'_'.$period->ends_on->format('Y-m-d').($period->isCorrection() ? '_Korrektur' : '');
            $title = __('VAT return :period', ['period' => $period->label()]);
            $report = $this->documents->store($period, 'vat_report', $this->report->pdf($period, $figures), $name.'.pdf', $title);
            $detail = $this->documents->store($period, 'vat_detail', $this->report->csv($period), $name.'_Detail.csv', $title.' – '.__('Detail'));

            $period->forceFill(['report_document_id' => $report->getKey(), 'detail_document_id' => $detail->getKey()])->save();

            return $period;
        });
    }
}
