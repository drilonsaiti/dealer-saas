<?php

namespace App\Domain\Import\Importers;

use App\Domain\Audit\Models\StatusHistory;
use App\Domain\Import\Enums\ImportRowAction;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Support\Normalize;
use App\Domain\Import\Support\PartyResolver;
use App\Domain\Import\Support\RowResult;
use App\Domain\Import\Support\SpreadsheetReader;
use App\Domain\Parties\Enums\PartyKind;
use App\Domain\Purchasing\Enums\PurchaseType;
use App\Domain\Purchasing\Enums\SellerKind;
use App\Domain\Purchasing\Enums\VatSituation;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Settings\Actions\IssueNumber;
use App\Domain\Settings\Enums\NumberSequenceKey;
use App\Domain\Settings\Models\NumberSequence;
use App\Domain\Vehicles\Enums\StockCycleStatus;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Domain\Vehicles\Support\Stammnummer;
use App\Domain\Vehicles\Support\Vin;
use App\Support\BusinessRuleException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Vehicles and their files from a stock list (Aziri's "Fahrzeuge" sheet, or any dealer's Excel).
 *
 * - The vehicle is found by Stammnummer, then VIN; a second row for the same car (buy-back)
 *   becomes a second file on the same vehicle, never a second vehicle.
 * - The file is found by its legacy number, so the import can run again until go-live.
 * - The file year is the purchase year, also when the car was sold the next year.
 * - Statuses are mapped ("Verkauft" → delivered, or sold without handover date; "Bestand" →
 *   ready for sale; "Rückgabe" → cancelled); imported files keep a status history entry.
 */
class VehicleImporter implements Importer
{
    /** Default status words (lower case) → status; overridable per preset (options.status_map). */
    public const STATUS_MAP = [
        'verkauft' => 'sold_or_delivered',
        'ausgeliefert' => 'delivered',
        'bestand' => 'ready_for_sale',
        'inseriert' => 'listed',
        'in aufbereitung' => 'in_preparation',
        'in prüfung' => 'in_review',
        'eingekauft' => 'purchased',
        'rückgabe' => 'cancelled',
        'rueckgabe' => 'cancelled',
        'storniert' => 'cancelled',
        'vendu' => 'sold_or_delivered',
        'en stock' => 'ready_for_sale',
        'venduto' => 'sold_or_delivered',
    ];

    public const PURCHASE_TYPE_MAP = [
        'direkt' => 'direct',
        'direktankauf' => 'direct',
        'eintausch' => 'trade_in',
        'rücknahme' => 'buyback',
        'rückkauf' => 'buyback',
        'kommission' => 'consignment',
        'ungeklärt' => 'direct',
    ];

    private const MAKES = ['Alfa Romeo', 'Aston Martin', 'Land Rover', 'Mercedes-Benz', 'Mercedes', 'Audi', 'BMW', 'Citroën', 'Citroen', 'Cupra', 'Dacia', 'DS', 'Fiat', 'Ford', 'Honda', 'Hyundai', 'Jaguar', 'Jeep', 'Kia', 'Lexus', 'Maserati', 'Mazda', 'Mini', 'Mitsubishi', 'Nissan', 'Opel', 'Peugeot', 'Porsche', 'Renault', 'Seat', 'Skoda', 'Škoda', 'Smart', 'Subaru', 'Suzuki', 'Tesla', 'Toyota', 'Volvo', 'VW', 'Volkswagen'];

    public function __construct(
        private readonly IssueNumber $issueNumber,
        private readonly PartyResolver $parties,
    ) {}

    public function fields(): array
    {
        return [
            'legacy_ref' => __('Your file number'),
            'stammnummer' => __('Stammnummer'),
            'vin' => __('VIN'),
            'label' => __('Vehicle (free text)'),
            'make' => __('Make'),
            'model' => __('Model'),
            'status' => __('Status'),
            'purchased_on' => __('Purchase date'),
            'purchase_price' => __('Purchase price'),
            'purchase_type' => __('Purchase type'),
            'seller' => __('Seller'),
            'sold_on' => __('Sale date'),
            'sale_price' => __('Sale price'),
            'buyer' => __('Buyer'),
            'list_price' => __('List price'),
            'first_registration_on' => __('First registration'),
            'body' => __('Body type'),
            'displacement_cc' => __('Displacement (cm³)'),
            'power_kw' => __('Power (kW)'),
            'type_approval' => __('Type approval'),
            'mfk_last_on' => __('Last MFK'),
            'color' => __('Exterior colour'),
            'mileage_in' => __('Mileage at purchase'),
            'mileage_out' => __('Mileage at handover'),
            'delivered_on' => __('Handover date'),
            'listed_on' => __('Listing date'),
            'notes' => __('Notes'),
        ];
    }

    public function guesses(): array
    {
        return [
            'legacy_ref' => ['Nr.', 'Nr', 'Nummer', 'Akte', 'File'],
            'stammnummer' => ['Stammnummer', 'Stamm-Nr.', 'Matricule', 'Numéro matricule'],
            'vin' => ['VIN', 'Fahrgestellnummer', 'Chassis'],
            'label' => ['Fahrzeug', 'Bezeichnung', 'Véhicule', 'Vehicle'],
            'make' => ['Marke', 'Marque', 'Make'],
            'model' => ['Modell', 'Modèle', 'Model'],
            'status' => ['Status', 'Status bestätigt', 'Statut'],
            'purchased_on' => ['EK Datum', 'Einkaufsdatum', 'Kaufdatum', 'Date achat'],
            'purchase_price' => ['EK CHF', 'Einkaufspreis', 'EK', 'Prix achat'],
            'purchase_type' => ['Einkaufsart', 'Type achat'],
            'seller' => ['Verkäufer', 'Lieferant', 'Vendeur'],
            'sold_on' => ['VK Datum', 'Verkaufsdatum', 'Date vente'],
            'sale_price' => ['VK CHF', 'Verkaufspreis', 'VK', 'Prix vente'],
            'buyer' => ['Käufer', 'Kunde', 'Acheteur'],
            'list_price' => ['Inserat CHF', 'Inseratspreis', 'Listenpreis', 'Prix affiché'],
            'first_registration_on' => ['Erstzulassung', '1. Inverkehrsetzung', 'Mise en circulation'],
            'body' => ['Aufbau', 'Carrosserie'],
            'displacement_cc' => ['Hubraum cm³', 'Hubraum', 'Cylindrée'],
            'power_kw' => ['Leistung kW', 'Leistung', 'Puissance'],
            'type_approval' => ['Typengenehmigung', 'Typenschein'],
            'mfk_last_on' => ['MFK Datum', 'MFK', 'Expertise'],
            'color' => ['Farbe', 'Couleur'],
            'mileage_in' => ['KM Einkauf', 'Km Einkauf', 'KM'],
            'mileage_out' => ['KM Verkauf', 'Km Verkauf'],
            'delivered_on' => ['Übergabedatum', 'Auslieferung', 'Date livraison'],
            'listed_on' => ['Inseratsdatum', 'Inseriert am'],
            'notes' => ['Bemerkung', 'Bemerkungen', 'Prüfhinweise', 'Remarques'],
        ];
    }

    public function rows(ImportRun $run, string $localPath): iterable
    {
        $reader = new SpreadsheetReader($localPath, $run->file_name);
        $mapping = array_filter($run->mapping ?? []);

        foreach ($reader->rows($run->option('sheet')) as $line => $values) {
            $payload = [];

            foreach ($mapping as $field => $header) {
                $payload[$field] = $values[$header] ?? null;
            }

            $payload = array_map(fn (mixed $v): mixed => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v, $payload);

            yield __('Row :number', ['number' => $line]) => $payload;
        }
    }

    public function importRow(ImportRun $run, RowResult $result, bool $dryRun): void
    {
        $p = $result->payload;

        $legacyRef = Normalize::text($p['legacy_ref'] ?? null);
        $stammnummer = Normalize::text($p['stammnummer'] ?? null);
        $vin = Normalize::text($p['vin'] ?? null);

        if ($stammnummer !== null && ! Stammnummer::isValid($stammnummer)) {
            $result->note(__('Stammnummer ":value" is not valid; imported without it.', ['value' => $stammnummer]));
            $stammnummer = null;
        }

        if ($vin !== null && ! Vin::isValid($vin)) {
            $result->note(__('VIN ":value" is not valid; imported without it.', ['value' => $vin]));
            $vin = null;
        }

        if ($legacyRef === null && $stammnummer === null && $vin === null && Normalize::text($p['label'] ?? null) === null) {
            $result->action(ImportRowAction::Skip)->note(__('Empty row.'));

            return;
        }

        $purchasedOn = Normalize::date($p['purchased_on'] ?? null);
        $soldOn = Normalize::date($p['sold_on'] ?? null);
        $deliveredOn = Normalize::date($p['delivered_on'] ?? null);

        $cycle = $legacyRef === null ? null : StockCycle::query()->with('vehicle')
            ->where(fn ($query) => $query->where('legacy_ref', $legacyRef)->orWhere('number', $legacyRef))
            ->first();
        $vehicle = $cycle !== null ? $cycle->vehicle : $this->findVehicle($stammnummer, $vin);
        $isNew = $cycle === null;

        if ($vehicle === null) {
            $vehicle = new Vehicle;
            $creatingVehicle = true;
        } else {
            $creatingVehicle = false;
        }

        $vehicle->fill(array_filter($this->vehicleAttributes($p, $stammnummer, $vin, $result), fn (mixed $v): bool => $v !== null));
        $vehicle->save();

        if ($creatingVehicle) {
            $result->created($vehicle);
        }

        $status = $this->status($run, $p, $soldOn, $deliveredOn, $purchasedOn, $result);

        if ($isNew) {
            $cycle = new StockCycle(['vehicle_id' => $vehicle->getKey(), 'legacy_ref' => $legacyRef]);
        }

        $cycle->fill(array_filter([
            'list_price_rp' => Normalize::money($p['list_price'] ?? null),
            'mileage_in' => Normalize::integer($p['mileage_in'] ?? null),
            'notes' => Normalize::text($p['notes'] ?? null),
        ], fn (mixed $v): bool => $v !== null));

        $previousStatus = $isNew ? null : $cycle->status;

        $cycle->forceFill(array_filter([
            'status' => $status,
            'purchased_on' => $purchasedOn,
            'file_year' => $purchasedOn === null ? null : (int) substr($purchasedOn, 0, 4),
            'sold_on' => $soldOn,
            'delivered_on' => $deliveredOn ?? ($status === StockCycleStatus::Delivered ? $soldOn : null),
            'listed_on' => Normalize::date($p['listed_on'] ?? null),
            'mileage_out' => Normalize::integer($p['mileage_out'] ?? null),
        ], fn (mixed $v): bool => $v !== null));

        if ($cycle->number === null && $purchasedOn !== null) {
            $cycle->number = $this->number($legacyRef, $purchasedOn);
        }

        $numberTaken = $cycle->number !== null && StockCycle::query()
            ->where('number', $cycle->number)
            ->when($cycle->exists, fn ($query) => $query->whereKeyNot($cycle->getKey()))
            ->exists();

        if ($numberTaken) {
            throw new BusinessRuleException(__('File number :number is already used by another vehicle file.', ['number' => $cycle->number]));
        }

        try {
            $cycle->save();
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException(__('This vehicle is already in stock with another open file; check the status of both rows.'));
        }

        if ($isNew) {
            $result->created($cycle);
        }

        if ($previousStatus !== $status) {
            StatusHistory::record($cycle, $previousStatus?->value, $status->value, __('Import'));
        }

        $this->purchase($cycle, $p, $purchasedOn, $result);
        $this->sale($cycle, $p, $status, $soldOn, $deliveredOn, $result);

        $result->action($isNew ? ImportRowAction::Create : ImportRowAction::Update, $cycle);
    }

    public function afterCommit(ImportRun $run): void
    {
        // New files continue after the highest imported number of the current year.
        $year = (int) now()->format('Y');
        $sequence = NumberSequence::query()->where('key', NumberSequenceKey::StockCycle)->first();

        if ($sequence === null) {
            return;
        }

        $highest = StockCycle::query()
            ->where('number', 'like', $year.'-%')
            ->pluck('number')
            ->map(fn (string $number): int => (int) substr($number, strlen((string) $year) + 1))
            ->max();

        if ($highest !== null && ($sequence->current_year !== $year || $sequence->next_value <= $highest)) {
            $sequence->forceFill(['next_value' => $highest + 1, 'current_year' => $year])->save();
        }
    }

    private function findVehicle(?string $stammnummer, ?string $vin): ?Vehicle
    {
        if ($stammnummer !== null) {
            $vehicle = Vehicle::query()->where('stammnummer', Stammnummer::normalize($stammnummer))->first();

            if ($vehicle !== null) {
                return $vehicle;
            }
        }

        return $vin === null ? null : Vehicle::query()->where('vin', Vin::normalize($vin))->first();
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function vehicleAttributes(array $p, ?string $stammnummer, ?string $vin, RowResult $result): array
    {
        $label = Normalize::text($p['label'] ?? null);
        $make = Normalize::text($p['make'] ?? null);
        $model = Normalize::text($p['model'] ?? null);

        if ($make === null && $label !== null) {
            [$make, $model] = $this->parseLabel($label);

            if ($make === null) {
                $result->note(__('Make not recognised in ":label"; please complete it.', ['label' => $label]));
            }
        }

        return [
            'stammnummer' => $stammnummer,
            'vin' => $vin,
            'internal_label' => $label,
            'make' => $make,
            'model' => $model,
            'first_registration_on' => Normalize::date($p['first_registration_on'] ?? null),
            'displacement_cc' => Normalize::integer($p['displacement_cc'] ?? null),
            'power_kw' => Normalize::integer($p['power_kw'] ?? null),
            'type_approval' => Normalize::text($p['type_approval'] ?? null),
            'mfk_last_on' => Normalize::date($p['mfk_last_on'] ?? null),
            'color_exterior' => Normalize::text($p['color'] ?? null),
        ];
    }

    /**
     * "BMW X3 30i Blau Shema" → [BMW, X3].
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function parseLabel(string $label): array
    {
        foreach (self::MAKES as $make) {
            if (stripos($label.' ', $make.' ') === 0) {
                $rest = trim(substr($label, strlen($make)));
                $model = $rest === '' ? null : explode(' ', $rest)[0];

                return [$make === 'Volkswagen' ? 'VW' : $make, $model];
            }
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function status(ImportRun $run, array $p, ?string $soldOn, ?string $deliveredOn, ?string $purchasedOn, RowResult $result): StockCycleStatus
    {
        $word = mb_strtolower((string) Normalize::text($p['status'] ?? null));
        $map = array_change_key_case([...Normalize::enumLabels(StockCycleStatus::class), ...self::STATUS_MAP, ...(array) $run->option('status_map', [])], CASE_LOWER);
        $mapped = $word === '' ? null : ($map[$word] ?? null);

        if ($word !== '' && $mapped === null) {
            $result->note(__('Status ":value" not recognised; derived from the dates.', ['value' => $word]));
        }

        $mapped ??= match (true) {
            $soldOn !== null => 'sold_or_delivered',
            $purchasedOn !== null => 'ready_for_sale',
            default => 'in_review',
        };

        if ($mapped === 'sold_or_delivered') {
            return $deliveredOn !== null || ($soldOn !== null && Carbon::parse($soldOn)->lt(now()->subDays(30)))
                ? StockCycleStatus::Delivered
                : StockCycleStatus::Sold;
        }

        return StockCycleStatus::from($mapped);
    }

    private function number(?string $legacyRef, string $purchasedOn): string
    {
        $year = substr($purchasedOn, 0, 4);

        if ($legacyRef !== null && ctype_digit($legacyRef)) {
            return $year.'-'.str_pad($legacyRef, 4, '0', STR_PAD_LEFT);
        }

        return ($this->issueNumber)(NumberSequenceKey::StockCycle);
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function purchase(StockCycle $cycle, array $p, ?string $purchasedOn, RowResult $result): void
    {
        if ($purchasedOn === null) {
            return;
        }

        $price = Normalize::money($p['purchase_price'] ?? null);

        if ($price === null) {
            $result->note(__('Purchase price missing; recorded as 0.00.'));
        }

        $typeWord = mb_strtolower((string) Normalize::text($p['purchase_type'] ?? null));
        $type = PurchaseType::tryFrom([...Normalize::enumLabels(PurchaseType::class), ...self::PURCHASE_TYPE_MAP][$typeWord] ?? '') ?? PurchaseType::Direct;

        if ($typeWord === 'ungeklärt') {
            $result->note(__('Purchase type unclear: please clarify.'));
        }

        $sellerName = Normalize::text($p['seller'] ?? null);
        $seller = $sellerName === null ? null : $this->parties->resolve($sellerName, seller: true, result: $result);
        $sellerKind = $seller === null || $seller->kind === PartyKind::Person ? SellerKind::Private : SellerKind::Company;

        $purchase = Purchase::query()->firstOrNew(['stock_cycle_id' => $cycle->getKey()]);
        $isNew = ! $purchase->exists;
        $purchase->fill([
            'seller_party_id' => $seller?->getKey() ?? $purchase->seller_party_id,
            'seller_kind' => $sellerKind,
            'purchase_type' => $type,
            'contract_on' => $purchasedOn,
            'price_rp' => $price ?? $purchase->price_rp ?? 0,
            'vat_situation' => $purchase->vat_situation ?? ($sellerKind === SellerKind::Private ? VatSituation::PrivateNoVat : VatSituation::Unknown),
            'mileage' => Normalize::integer($p['mileage_in'] ?? null),
        ])->save();

        if ($isNew) {
            $result->created($purchase);
        }
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function sale(StockCycle $cycle, array $p, StockCycleStatus $status, ?string $soldOn, ?string $deliveredOn, RowResult $result): void
    {
        if (! in_array($status, [StockCycleStatus::Sold, StockCycleStatus::Delivered, StockCycleStatus::Archived], true)) {
            return;
        }

        $buyerName = Normalize::text($p['buyer'] ?? null);
        $buyer = $buyerName === null
            ? $this->parties->unknownBuyer($result)
            : $this->parties->resolve($buyerName, seller: false, result: $result);
        $price = Normalize::money($p['sale_price'] ?? null);

        if ($price === null) {
            $result->note(__('Sale price missing; recorded as 0.00.'));
        }

        $sale = Sale::query()->active()->where('stock_cycle_id', $cycle->getKey())->first() ?? new Sale(['stock_cycle_id' => $cycle->getKey()]);
        $isNew = ! $sale->exists;

        $sale->fill([
            'buyer_party_id' => $buyer->getKey(),
            'sale_on' => $soldOn,
            'price_rp' => $price ?? $sale->price_rp ?? 0,
            'legacy_ref' => $cycle->legacy_ref ?? 'import',
        ]);
        $sale->forceFill([
            'status' => $status === StockCycleStatus::Sold ? SaleStatus::Contracted : SaleStatus::Delivered,
            'delivered_on' => $status === StockCycleStatus::Sold ? null : ($deliveredOn ?? $soldOn),
            'mileage_at_handover' => Normalize::integer($p['mileage_out'] ?? null),
        ])->save();

        if ($isNew) {
            $result->created($sale);
        }
    }
}
