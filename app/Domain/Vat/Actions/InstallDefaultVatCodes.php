<?php

namespace App\Domain\Vat\Actions;

use App\Domain\Vat\Enums\VatCodeKind;
use App\Domain\Vat\Models\VatCode;

/**
 * The VAT codes every dealer starts with. Safe to run again (adds only missing codes).
 */
class InstallDefaultVatCodes
{
    public const TAXABLE_NORMAL = 'taxable_normal';

    public const TAXABLE_REDUCED = 'taxable_reduced';

    public const NO_TAX_SHOWN = 'no_tax_shown';

    public const EXPORT_EXEMPT = 'export_exempt';

    public const EXCLUDED = 'excluded';

    private const DEFAULTS = [
        self::TAXABLE_NORMAL => [VatCodeKind::Taxable, 'normal', 'Steuerbar, Normalsatz', 'Imposable, taux normal', 'Imponibile, aliquota normale', 'Taxable, standard rate'],
        self::TAXABLE_REDUCED => [VatCodeKind::Taxable, 'reduced', 'Steuerbar, reduzierter Satz', 'Imposable, taux réduit', 'Imponibile, aliquota ridotta', 'Taxable, reduced rate'],
        self::NO_TAX_SHOWN => [VatCodeKind::NoTaxShown, null, 'Ohne MWST-Ausweis', 'Sans TVA indiquée', 'Senza IVA indicata', 'No VAT shown'],
        self::EXPORT_EXEMPT => [VatCodeKind::ExportExempt, null, 'Export, steuerbefreit', 'Exportation, exonérée', 'Esportazione, esente', 'Export, exempt'],
        self::EXCLUDED => [VatCodeKind::Excluded, null, 'Von der Steuer ausgenommen', 'Exclu du champ de l’impôt', 'Escluso dall’imposta', 'Excluded from VAT'],
    ];

    public function __invoke(): void
    {
        $existing = VatCode::query()->pluck('key')->all();

        foreach (self::DEFAULTS as $key => [$kind, $rate, $de, $fr, $it, $en]) {
            if (! in_array($key, $existing, true)) {
                VatCode::create(['key' => $key, 'kind' => $kind, 'vat_rate_code' => $rate, 'label' => ['de' => $de, 'fr' => $fr, 'it' => $it, 'en' => $en]]);
            }
        }
    }
}
