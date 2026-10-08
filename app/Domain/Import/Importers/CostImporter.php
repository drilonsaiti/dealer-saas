<?php

namespace App\Domain\Import\Importers;

use App\Domain\Import\Enums\ImportRowAction;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Support\Normalize;
use App\Domain\Import\Support\PartyResolver;
use App\Domain\Import\Support\RowResult;
use App\Domain\Import\Support\SpreadsheetReader;
use App\Domain\Purchasing\Enums\CostStatus;
use App\Domain\Purchasing\Models\Cost;
use App\Domain\Purchasing\Models\CostCategory;
use App\Domain\Vehicles\Models\StockCycle;
use App\Support\BusinessRuleException;

/**
 * Cost lines (Aziri's "Kosten" sheet, K0001…): linked to the vehicle file by its legacy
 * number, imported as drafts. Unknown categories land in "To be clarified".
 */
class CostImporter implements Importer
{
    /** Keyword in the source category or description → cost category key. */
    public const CATEGORY_KEYWORDS = [
        'transport' => 'transport',
        'reparatur' => 'repair',
        'werkstatt' => 'repair',
        'service' => 'repair',
        'teile' => 'parts',
        'reifen' => 'tyres',
        'pneu' => 'tyres',
        'aufbereitung' => 'preparation',
        'reinigung' => 'preparation',
        'mfk' => 'inspection',
        'prüfung' => 'inspection',
        'garantie' => 'warranty',
        'auktion' => 'auction_fees',
        'zulassung' => 'registration',
        'gebühr' => 'registration',
        'provision' => 'commission',
    ];

    public function __construct(private readonly PartyResolver $parties) {}

    public function fields(): array
    {
        return [
            'legacy_ref' => __('Cost number'),
            'cycle_ref' => __('Vehicle file number'),
            'incurred_on' => __('Date'),
            'amount' => __('Amount incl. VAT'),
            'category' => __('Category'),
            'description' => __('Description'),
            'supplier' => __('Supplier'),
        ];
    }

    public function guesses(): array
    {
        return [
            'legacy_ref' => ['Kosten-ID', 'ID', 'Nr.', 'Kosten Nr.'],
            'cycle_ref' => ['Fahrzeug Nr.', 'Fahrzeug-Nr.', 'Akte', 'Fahrzeug'],
            'incurred_on' => ['Datum', 'Date'],
            'amount' => ['Betrag CHF', 'Betrag', 'CHF', 'Montant'],
            'category' => ['Kategorie', 'Kostenart', 'Art', 'Catégorie'],
            'description' => ['Beschreibung', 'Text', 'Bemerkung', 'Description'],
            'supplier' => ['Lieferant', 'Werkstatt', 'Fournisseur'],
        ];
    }

    public function rows(ImportRun $run, string $localPath): iterable
    {
        $reader = new SpreadsheetReader($localPath, $run->file_name);
        $mapping = array_filter($run->mapping ?? []);

        foreach ($reader->rows($run->option('sheet')) as $line => $values) {
            $payload = [];

            foreach ($mapping as $field => $header) {
                $value = $values[$header] ?? null;
                $payload[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
            }

            yield __('Row :number', ['number' => $line]) => $payload;
        }
    }

    public function importRow(ImportRun $run, RowResult $result, bool $dryRun): void
    {
        $p = $result->payload;
        $amount = Normalize::money($p['amount'] ?? null);
        $date = Normalize::date($p['incurred_on'] ?? null);

        if ($amount === null || $date === null) {
            throw new BusinessRuleException(__('Amount and date are needed for a cost.'));
        }

        $cycleRef = Normalize::text($p['cycle_ref'] ?? null);
        $cycle = $cycleRef === null ? null : StockCycle::query()->where('legacy_ref', $cycleRef)->orWhere('number', $cycleRef)->first();

        if ($cycleRef !== null && $cycle === null) {
            throw new BusinessRuleException(__('Vehicle file ":ref" not found; import the vehicles first.', ['ref' => $cycleRef]));
        }

        $legacyRef = Normalize::text($p['legacy_ref'] ?? null);
        $cost = $legacyRef === null ? null : Cost::query()->where('legacy_ref', $legacyRef)->first();

        if ($cost?->status === CostStatus::Confirmed) {
            $result->action(ImportRowAction::Skip, $cost)->note(__('Already confirmed; not changed.'));

            return;
        }

        $supplierName = Normalize::text($p['supplier'] ?? null);
        $isNew = $cost === null;
        $cost ??= new Cost(['legacy_ref' => $legacyRef]);

        $cost->fill([
            'stock_cycle_id' => $cycle?->getKey(),
            'category_id' => $this->category($p, $result)->getKey(),
            'incurred_on' => $date,
            'description' => Normalize::text($p['description'] ?? null),
            'supplier_party_id' => $supplierName === null ? null : $this->parties->resolve($supplierName, seller: true, result: $result)->getKey(),
            'gross_rp' => $amount,
        ])->save();

        if ($isNew) {
            $result->created($cost);
        }

        $result->action($isNew ? ImportRowAction::Create : ImportRowAction::Update, $cost);
    }

    public function afterCommit(ImportRun $run): void {}

    /**
     * @param  array<string, mixed>  $p
     */
    private function category(array $p, RowResult $result): CostCategory
    {
        $text = mb_strtolower(trim(((string) Normalize::text($p['category'] ?? null)).' '.((string) Normalize::text($p['description'] ?? null))));
        $key = 'unclear';

        foreach (self::CATEGORY_KEYWORDS as $word => $category) {
            if ($text !== '' && str_contains($text, $word)) {
                $key = $category;

                break;
            }
        }

        if ($key === 'unclear') {
            $result->note(__('Category unclear: please assign it.'));
        }

        return CostCategory::query()->where('key', $key)->firstOrFail();
    }
}
