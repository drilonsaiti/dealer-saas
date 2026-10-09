<?php

namespace App\Domain\Vehicles\Actions;

use App\Domain\Vehicles\Enums\Code178Status;
use App\Domain\Vehicles\Models\Vehicle;
use Illuminate\Support\Carbon;

/**
 * Code 178 in the registration document ("Halterwechsel verboten"): entered by the leasing
 * bank, cleared when the contract ends. While entered, the car cannot be resold.
 */
class SetCode178
{
    public function __invoke(Vehicle $vehicle, Code178Status $status, ?string $on = null, ?string $note = null): Vehicle
    {
        $vehicle->forceFill([
            'code178_status' => $status,
            'code178_changed_on' => Carbon::parse($on ?? Carbon::today())->toDateString(),
            'code178_note' => $note,
        ])->save();

        return $vehicle;
    }
}
