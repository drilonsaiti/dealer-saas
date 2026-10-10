<?php

namespace App\Domain\VehicleData\Support;

final readonly class VehicleValuation
{
    public function __construct(
        public ?int $retailRp,
        public ?int $tradeInRp,
        public ?string $reference = null,
    ) {}
}
