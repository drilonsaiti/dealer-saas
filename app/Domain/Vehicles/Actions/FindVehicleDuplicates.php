<?php

namespace App\Domain\Vehicles\Actions;

use App\Domain\Vehicles\Models\Vehicle;
use App\Domain\Vehicles\Support\Stammnummer;
use App\Domain\Vehicles\Support\Vin;
use Illuminate\Database\Eloquent\Collection;

/**
 * Looks up vehicles the user may be about to enter twice.
 * The Stammnummer is unique (enforced by the database); a VIN match is only a warning,
 * because a VIN can be mistyped on old paperwork and must not block the entry.
 */
class FindVehicleDuplicates
{
    public function byStammnummer(?string $stammnummer, ?string $ignoreId = null): ?Vehicle
    {
        $normalized = Stammnummer::normalize($stammnummer);

        if ($normalized === null) {
            return null;
        }

        return Vehicle::query()
            ->where('stammnummer', $normalized)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->first();
    }

    /**
     * @return Collection<int, Vehicle>
     */
    public function byVin(?string $vin, ?string $ignoreId = null): Collection
    {
        $normalized = Vin::normalize($vin);

        if ($normalized === null) {
            return new Collection;
        }

        return Vehicle::query()
            ->where('vin', $normalized)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get();
    }
}
