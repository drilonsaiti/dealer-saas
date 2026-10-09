<?php

namespace App\Domain\Vat\Support;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Vat\Enums\VatMethod;
use App\Domain\Vat\Models\VatPeriod;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Carbon;

/**
 * The closed return as eCH-0217 v2.0.0 XML ("VATDeclaration") for upload in the ESTV portal.
 * Net tax rate method: simpleTaxRateMethod for periods from 2025 (activity code + rate +
 * gross turnover per approved rate), netTaxRateMethod before. A correction (typeOfSubmission 2)
 * carries the full amounts and replaces the original return.
 *
 * Source: eCH-0217 V2.0.0, https://www.ech.ch/fr/ech/ech-0217/2.0.0 (structure checked against the
 * official example files in tests/Fixtures/ech0217; full schema check with VAT_ECH0217_XSD)
 */
class Ech0217Exporter
{
    public const NAMESPACE = 'http://www.ech.ch/xmlns/eCH-0217/2';

    public const NAMESPACE_0058 = 'http://www.ech.ch/xmlns/eCH-0058/5';

    public const SIMPLE_METHOD_FROM = '2025-01-01';

    /**
     * What is missing before the XML can be built.
     *
     * @param  array<string, mixed>  $figures
     * @return list<string>
     */
    public function problems(VatPeriod $period, array $figures, ?Tenant $tenant): array
    {
        $problems = [];

        if (self::uid($tenant) === null) {
            $problems[] = __('Enter the company UID (CHE-…) in Settings → Company.');
        }

        if ($period->profile->method !== VatMethod::NetTaxRate) {
            $problems[] = __('The XML export supports the net tax rate method only.');
        }

        if ($this->simple($period)) {
            foreach ($figures['rates'] ?? [] as $rate) {
                if (! preg_match('/^\d{5}$/', (string) ($rate['activity_code'] ?? ''))) {
                    $problems[] = __('Enter the five-digit ESTV activity code for the net tax rate :rate % (Settings → VAT).', ['rate' => rtrim(rtrim((string) $rate['rate'], '0'), '.')]);
                }
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $figures  frozen figures of the period (PeriodCalculator)
     */
    public function xml(VatPeriod $period, array $figures, Tenant $tenant, ?Carbon $generatedAt = null): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElementNS(self::NAMESPACE, 'eCH-0217:VATDeclaration');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:eCH-0058', self::NAMESPACE_0058);
        $doc->appendChild($root);
        $add = function (DOMElement $parent, string $name, ?string $value = null) use ($doc): DOMElement {
            $element = $doc->createElementNS(self::NAMESPACE, 'eCH-0217:'.$name);

            if ($value !== null) {
                $element->appendChild($doc->createTextNode($value));
            }

            $parent->appendChild($element);

            return $element;
        };

        $general = $add($root, 'generalInformation');
        $add($general, 'uid', (string) self::uid($tenant));
        $add($general, 'organisationName', mb_substr((string) ($tenant->legal_name ?: $tenant->name), 0, 255));
        $add($general, 'generationTime', ($generatedAt ?? now())->copy()->utc()->format('Y-m-d\TH:i:s\Z'));
        $add($general, 'reportingPeriodFrom', $period->starts_on->format('Y-m-d'));
        $add($general, 'reportingPeriodTill', $period->ends_on->format('Y-m-d'));
        $add($general, 'typeOfSubmission', $period->isCorrection() ? '2' : '1');
        $add($general, 'formOfReporting', (string) $period->profile->basis->formOfReporting());
        $add($general, 'businessReferenceId', $this->businessReference($period));
        $application = $add($general, 'sendingApplication');

        foreach (['manufacturer' => 'Dealer SaaS', 'product' => (string) config('app.name'), 'productVersion' => (string) config('dealer.version', '1.0')] as $name => $value) {
            $application->appendChild($doc->createElementNS(self::NAMESPACE_0058, 'eCH-0058:'.$name, $value));
        }

        $fields = $figures['fields'];
        $turnover = $add($root, 'turnoverComputation');
        $add($turnover, 'totalConsideration', self::amount((int) $fields[200]));

        foreach ([220 => 'suppliesToForeignCountries', 221 => 'suppliesAbroad', 225 => 'transferNotificationProcedure', 230 => 'suppliesExemptFromTax', 235 => 'reductionOfConsideration'] as $field => $name) {
            if ((int) ($fields[$field] ?? 0) !== 0) {
                $add($turnover, $name, self::amount((int) $fields[$field]));
            }
        }

        if ((int) ($fields[280] ?? 0) !== 0) {
            $various = $add($turnover, 'variousDeduction');
            $add($various, 'amountVariousDeduction', self::amount((int) $fields[280]));
            $add($various, 'descriptionVariousDeduction', 'Diverses');
        }

        $method = $add($root, $this->simple($period) ? 'simpleTaxRateMethod' : 'netTaxRateMethod');

        foreach ($figures['rates'] as $rate) {
            $supplies = $add($method, 'suppliesPerTaxRate');

            if ($this->simple($period)) {
                $add($supplies, 'activityID', (string) $rate['activity_code']);
            }

            $add($supplies, 'taxRate', self::percent((string) $rate['rate']));
            $add($supplies, 'turnover', self::amount((int) $rate['turnover_rp']));
        }

        $add($root, 'payableTax', self::amount((int) $figures['payable_rp']));

        return (string) $doc->saveXML();
    }

    /**
     * Well-formed always; against the eCH-0217 schema when it is configured.
     *
     * @return array{validated: bool, errors: list<string>}
     */
    public function validate(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $doc = new DOMDocument;

            if (! $doc->loadXML($xml)) {
                return ['validated' => false, 'errors' => $this->libxmlErrors()];
            }

            $xsd = config('dealer.vat.ech0217_xsd');

            if (! is_string($xsd) || $xsd === '' || ! is_file($xsd)) {
                return ['validated' => false, 'errors' => []];
            }

            return $doc->schemaValidate($xsd)
                ? ['validated' => true, 'errors' => []]
                : ['validated' => false, 'errors' => $this->libxmlErrors() ?: ['invalid']];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function uid(?Tenant $tenant): ?string
    {
        foreach ([$tenant?->uid, $tenant?->vat_number] as $value) {
            $digits = preg_replace('/\D/', '', (string) $value);

            if (is_string($digits) && strlen($digits) === 9 && str_starts_with(strtoupper(trim((string) $value)), 'CHE')) {
                return 'CHE'.$digits;
            }
        }

        return null;
    }

    public static function amount(int $rappen): string
    {
        return number_format($rappen / 100, 2, '.', '');
    }

    public static function percent(string $rate): string
    {
        $value = rtrim(rtrim(number_format((float) $rate, 4, '.', ''), '0'), '.');

        return str_contains($value, '.') && strlen(substr((string) strrchr($value, '.'), 1)) >= 2 ? $value : number_format((float) $rate, 2, '.', '');
    }

    public function fileName(VatPeriod $period): string
    {
        return 'MWST_'.$period->starts_on->format('Y-m-d').'_'.$period->ends_on->format('Y-m-d').($period->isCorrection() ? '_Korrektur' : '').'_eCH-0217.xml';
    }

    private function simple(VatPeriod $period): bool
    {
        return $period->starts_on->format('Y-m-d') >= self::SIMPLE_METHOD_FROM;
    }

    private function businessReference(VatPeriod $period): string
    {
        return 'MWST-'.$period->starts_on->format('Ymd').'-'.$period->ends_on->format('Ymd').($period->isCorrection() ? '-K'.substr($period->getKey(), 0, 8) : '');
    }

    /**
     * @return list<string>
     */
    private function libxmlErrors(): array
    {
        return array_map(fn ($e): string => trim($e->message).' (line '.$e->line.')', libxml_get_errors());
    }
}
