<?php

namespace App\Domain\Vat\Support;

use App\Domain\Documents\Support\PdfRenderer;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Vat\Models\TaxEvent;
use App\Domain\Vat\Models\VatPeriod;
use App\Support\BusinessRuleException;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * The closed return as PDF (figures per ESTV field, to type in by hand if an upload is ever
 * rejected) and the detail of every entry as CSV (semicolon, UTF-8 with BOM for Excel).
 */
class VatReport
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $figures
     */
    public function html(VatPeriod $period, array $figures): string
    {
        return view('documents.vat.report', [
            'period' => $period,
            'f' => $figures,
            'tenant' => $this->context->tenant(),
            'events' => $this->events($period),
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $figures
     */
    public function pdf(VatPeriod $period, array $figures): string
    {
        if (! $this->pdf->isAvailable()) {
            throw new BusinessRuleException(__('PDFs cannot be created: the PDF service (Gotenberg) is not configured.'));
        }

        try {
            return $this->pdf->fromHtml($this->html($period, $figures));
        } catch (Throwable $e) {
            report($e);

            throw new BusinessRuleException(__('The PDF could not be created. Is the PDF service (Gotenberg) running?'));
        }
    }

    public function csv(VatPeriod $period): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [__('Date'), __('Invoice'), __('Vehicle'), __('VAT code'), __('Field'), __('Amount'), __('VAT rate'), __('Net tax rate'), __('Tax'), __('Status'), __('Late'), __('Rule'), __('Explanation')], ';', '"', '');

        foreach ($this->events($period) as $event) {
            fputcsv($out, [
                $event->event_on->format('d.m.Y'),
                $event->invoice?->number,
                $event->invoice?->stockCycle?->number,
                $event->kind->value,
                $event->field,
                number_format($event->base_rp / 100, 2, '.', ''),
                (float) $event->legal_rate > 0 ? $event->legal_rate : '',
                $event->net_rate ?? '',
                number_format($event->tax_rp / 100, 2, '.', ''),
                $event->counts() ? __('Counts') : $event->state->getLabel(),
                $event->late ? __('Yes') : '',
                $event->rule_key.' '.$event->rule_version,
                $event->explanationText(),
            ], ';', '"', '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * @return Collection<int, TaxEvent>
     */
    private function events(VatPeriod $period)
    {
        return TaxEvent::query()->with(['invoice.stockCycle'])
            ->whereIn('period_id', VatPeriods::chainIds($period))
            ->orderBy('event_on')->orderBy('created_at')->get();
    }
}
