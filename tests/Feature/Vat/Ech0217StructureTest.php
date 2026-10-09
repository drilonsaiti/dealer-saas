<?php

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Vat\Models\VatPeriod;
use App\Domain\Vat\Models\VatProfile;
use App\Domain\Vat\Support\Ech0217Exporter;
use Illuminate\Support\Carbon;

/*
 * The export must use the namespaces, element names and order of the official eCH-0217 V2.0.0
 * example (tests/Fixtures/ech0217). The full XSD check runs when VAT_ECH0217_XSD is set.
 */

/**
 * Element paths in document order, as "{namespace}name/{namespace}name…".
 *
 * @return list<string>
 */
function ech0217Paths(string $xml): array
{
    $doc = new DOMDocument;
    $doc->loadXML($xml);
    $paths = [];
    $walk = function (DOMElement $element, string $prefix) use (&$walk, &$paths): void {
        $path = $prefix.'/{'.$element->namespaceURI.'}'.$element->localName;
        $paths[] = $path;

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $walk($child, $path);
            }
        }
    };
    $walk($doc->documentElement, '');

    return $paths;
}

it('builds the XML with the structure of the official eCH-0217 example', function () {
    $tenant = new Tenant(['name' => 'Aziri Automobile GmbH', 'legal_name' => 'Aziri Automobile GmbH', 'uid' => 'CHE-404.944.758']);
    $period = new VatPeriod(['starts_on' => '2026-01-01', 'ends_on' => '2026-06-30']);
    $period->setRelation('profile', new VatProfile(['method' => 'net_tax_rate', 'basis' => 'agreed', 'period' => 'half_year']));
    $figures = [
        'fields' => [200 => 53_000_000, 220 => 0, 221 => 100_000, 225 => 200_000, 230 => 1_000_000, 235 => 500_000, 280 => 300_000, 289 => 2_100_000, 299 => 50_900_000],
        'rates' => [['activity_code' => '11111', 'rate' => '0.6000', 'turnover_rp' => 50_900_000, 'tax_rp' => 305_400]],
        'payable_rp' => 305_400,
    ];

    $ours = ech0217Paths(app(Ech0217Exporter::class)->xml($period, $figures, $tenant, Carbon::parse('2026-07-10 08:30', 'Europe/Zurich')));
    $official = ech0217Paths((string) file_get_contents(base_path('tests/Fixtures/ech0217/example_simpleTaxRateMethod.xml')));

    // Every element we write exists in the example, in the same order (we leave out optional ones).
    $position = -1;

    foreach ($ours as $path) {
        $found = array_search($path, array_slice($official, $position + 1), true);
        expect($found)->not->toBeFalse("{$path} is not in the official example (or out of order)");
        $position += $found + 1;
    }

    $xml = app(Ech0217Exporter::class)->xml($period, $figures, $tenant, Carbon::parse('2026-07-10 08:30', 'Europe/Zurich'));

    expect($ours)->toContain('/{http://www.ech.ch/xmlns/eCH-0217/2}VATDeclaration/{http://www.ech.ch/xmlns/eCH-0217/2}simpleTaxRateMethod/{http://www.ech.ch/xmlns/eCH-0217/2}suppliesPerTaxRate/{http://www.ech.ch/xmlns/eCH-0217/2}activityID')
        ->and($xml)->toContain('<eCH-0217:generationTime>2026-07-10T06:30:00Z</eCH-0217:generationTime>')
        ->and($xml)->toContain('<eCH-0217:variousDeduction>');
});

it('passes the official eCH-0217 schema when it is installed', function () {
    $xsd = base_path('resources/schemas/eCH-0217-2-0-0.xsd');

    if (! is_file($xsd)) {
        $this->markTestSkipped('resources/schemas/eCH-0217-2-0-0.xsd is not installed.');
    }

    config(['dealer.vat.ech0217_xsd' => $xsd]);
    $tenant = new Tenant(['name' => 'Aziri Automobile GmbH', 'uid' => 'CHE-404.944.758']);
    $period = new VatPeriod(['starts_on' => '2026-01-01', 'ends_on' => '2026-06-30']);
    $period->setRelation('profile', new VatProfile(['method' => 'net_tax_rate', 'basis' => 'agreed', 'period' => 'half_year']));
    $exporter = app(Ech0217Exporter::class);
    $xml = $exporter->xml($period, [
        'fields' => [200 => 50_000_000, 220 => 0, 221 => 0, 225 => 0, 230 => 0, 235 => 0, 280 => 0, 289 => 0, 299 => 50_000_000],
        'rates' => [['activity_code' => '11111', 'rate' => '0.6000', 'turnover_rp' => 50_000_000, 'tax_rp' => 300_000]],
        'payable_rp' => 300_000,
    ], $tenant);

    expect($exporter->validate($xml))->toBe(['validated' => true, 'errors' => []]);
});
