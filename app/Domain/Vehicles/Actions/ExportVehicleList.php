<?php

namespace App\Domain\Vehicles\Actions;

use App\Domain\Vehicles\Models\StockCycle;
use App\Support\SwissFormat;
use Illuminate\Database\Eloquent\Builder;

/**
 * The stock list as CSV for Excel (semicolon, UTF-8 with BOM). The column names are the ones
 * the vehicle import recognises, so an exported list can be corrected and imported back.
 */
class ExportVehicleList
{
    public const HEADERS = [
        'Nr.', 'Stammnummer', 'VIN', 'Marke', 'Modell', 'Status', 'EK Datum', 'EK CHF', 'Einkaufsart', 'Verkäufer',
        'VK Datum', 'VK CHF', 'Käufer', 'Inserat CHF', 'Erstzulassung', 'KM Einkauf', 'KM Verkauf', 'Übergabedatum',
    ];

    /**
     * Writes the file and returns its path.
     *
     * @param  Builder<StockCycle>|null  $query
     */
    public function __invoke(?Builder $query = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vehicles').'.csv';
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('Cannot write the export file.');
        }

        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, self::HEADERS, ';', escape: '');

        ($query ?? StockCycle::query())
            ->with(['vehicle', 'purchase.seller', 'activeSale.buyer'])
            ->orderBy('number')
            ->chunk(500, function ($cycles) use ($handle): void {
                foreach ($cycles as $cycle) {
                    fputcsv($handle, $this->row($cycle), ';', escape: '');
                }
            });

        fclose($handle);

        return $path;
    }

    /**
     * @return list<string|int|null>
     */
    private function row(StockCycle $cycle): array
    {
        $vehicle = $cycle->vehicle;
        $purchase = $cycle->purchase;
        $sale = $cycle->activeSale;
        $amount = fn (?int $rp): ?string => $rp === null ? null : number_format($rp / 100, 2, '.', '');
        $date = fn (?\DateTimeInterface $d): ?string => $d === null ? null : SwissFormat::date($d);

        return [
            $cycle->number,
            $vehicle->stammnummer,
            $vehicle->vin,
            $vehicle->make,
            $vehicle->model,
            $cycle->status->getLabel(),
            $date($cycle->purchased_on),
            $amount($purchase?->price_rp),
            $purchase?->purchase_type->getLabel(),
            $purchase?->seller?->displayName(),
            $date($cycle->sold_on),
            $amount($sale?->price_rp),
            $sale?->buyer?->displayName(),
            $amount($cycle->list_price_rp),
            $date($vehicle->first_registration_on),
            $cycle->mileage_in,
            $cycle->mileage_out,
            $date($cycle->delivered_on),
        ];
    }
}
