<?php

namespace App\Domain\VehicleData\Contracts;

use App\Domain\Integrations\Contracts\Integration;
use App\Domain\Integrations\Models\IntegrationAccount;
use App\Domain\VehicleData\Support\VehicleData;
use App\Domain\VehicleData\Support\VehicleValuation;
use Illuminate\Support\Carbon;

/**
 * A licensed source of vehicle data (Auto-i-DAT or similar): identification by type approval
 * or VIN, technical data, standard and optional equipment, valuation. Never scraped.
 */
interface VehicleDataProvider extends Integration
{
    /**
     * The variants matching a type approval (Typengenehmigung) and/or VIN.
     *
     * @return list<VehicleData>
     */
    public function lookup(IntegrationAccount $account, ?string $typeApproval, ?string $vin): array;

    public function valuation(IntegrationAccount $account, string $externalId, Carbon $firstRegistration, int $mileage): VehicleValuation;
}
