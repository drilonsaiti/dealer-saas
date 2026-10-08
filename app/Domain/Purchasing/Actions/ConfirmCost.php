<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\CostStatus;
use App\Domain\Purchasing\Models\Cost;

/**
 * The real invoice is in: the cost becomes final and stops being an estimate.
 */
class ConfirmCost
{
    public function __invoke(Cost $cost): Cost
    {
        $cost->forceFill(['status' => CostStatus::Confirmed, 'is_estimate' => false])->save();

        return $cost;
    }
}
