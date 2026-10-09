<?php

namespace App\Domain\Vat\Actions;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Vat\Enums\VatPeriodStatus;
use App\Domain\Vat\Models\VatPeriod;
use App\Domain\Vat\Support\Ech0217Exporter;
use App\Domain\Vat\Support\VatDocuments;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Builds the eCH-0217 XML from the frozen figures and files it. An export is not a
 * submission: the dealer uploads the file in the ESTV portal and then marks it submitted.
 */
class ExportVatPeriod
{
    public function __construct(
        private readonly Ech0217Exporter $exporter,
        private readonly VatDocuments $documents,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return array{period: VatPeriod, validated: bool}
     */
    public function __invoke(VatPeriod $period): array
    {
        if (! in_array($period->status, [VatPeriodStatus::Closed, VatPeriodStatus::Exported], true)) {
            throw new BusinessRuleException(__('Only a closed VAT period can be exported.'));
        }

        $tenant = $this->context->tenant();
        $figures = (array) $period->figures;
        $problems = $this->exporter->problems($period, $figures, $tenant);

        if ($problems !== [] || $tenant === null) {
            throw BusinessRuleException::because($problems);
        }

        $xml = $this->exporter->xml($period, $figures, $tenant);
        $check = $this->exporter->validate($xml);

        if ($check['errors'] !== []) {
            throw BusinessRuleException::because([__('The XML does not match the eCH-0217 schema:'), ...array_slice($check['errors'], 0, 5)]);
        }

        return DB::transaction(function () use ($period, $xml, $check): array {
            $title = __('VAT export :period', ['period' => $period->label()]);
            $document = $this->documents->store($period, 'vat_export', $xml, $this->exporter->fileName($period), $title);

            $figures = (array) $period->figures;
            $figures['xml_validated'] = $check['validated'];

            $period->forceFill([
                'status' => VatPeriodStatus::Exported,
                'exported_at' => now(),
                'xml_document_id' => $document->getKey(),
                'figures' => $figures,
            ])->save();

            return ['period' => $period, 'validated' => $check['validated']];
        });
    }
}
