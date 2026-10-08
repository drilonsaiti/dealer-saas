<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Models\CostCategory;

/**
 * Gives the current dealer the default cost categories (in all four languages).
 * Idempotent: existing keys are left alone, so a dealer's renamed categories stay renamed.
 */
class InstallDefaultCostCategories
{
    /**
     * key => [sort, counts toward margin, de, fr, it, en]
     */
    public const DEFAULTS = [
        'transport' => [10, true, 'Transport', 'Transport', 'Trasporto', 'Transport'],
        'repair' => [20, true, 'Reparatur / Werkstatt', 'Réparation / atelier', 'Riparazione / officina', 'Repair / workshop'],
        'parts' => [30, true, 'Ersatzteile', 'Pièces détachées', 'Pezzi di ricambio', 'Parts'],
        'tyres' => [40, true, 'Reifen', 'Pneus', 'Pneumatici', 'Tyres'],
        'preparation' => [50, true, 'Aufbereitung / Reinigung', 'Préparation / nettoyage', 'Preparazione / pulizia', 'Preparation / cleaning'],
        'inspection' => [60, true, 'MFK / Prüfung', 'Expertise / contrôle', 'Collaudo / controllo', 'Inspection (MFK)'],
        'warranty' => [70, true, 'Garantieprämie', 'Prime de garantie', 'Premio di garanzia', 'Warranty premium'],
        'auction_fees' => [80, true, 'Auktionsgebühren', 'Frais d’enchères', 'Spese d’asta', 'Auction fees'],
        'registration' => [90, true, 'Zulassung / Gebühren', 'Immatriculation / émoluments', 'Immatricolazione / tasse', 'Registration / fees'],
        'commission' => [100, true, 'Provision', 'Commission', 'Provvigione', 'Commission'],
        'other' => [110, true, 'Sonstiges', 'Divers', 'Varie', 'Other'],
        'unclear' => [120, true, 'Ungeklärt', 'À clarifier', 'Da chiarire', 'To be clarified'],
    ];

    public function __invoke(): int
    {
        $existing = CostCategory::query()->pluck('key')->all();
        $created = 0;

        foreach (self::DEFAULTS as $key => [$sort, $margin, $de, $fr, $it, $en]) {
            if (in_array($key, $existing, true)) {
                continue;
            }

            CostCategory::create([
                'key' => $key,
                'name' => ['de' => $de, 'fr' => $fr, 'it' => $it, 'en' => $en],
                'counts_toward_margin' => $margin,
                'sort' => $sort,
            ]);

            $created++;
        }

        return $created;
    }
}
