<?php

namespace App\Domain\Pricing\Support;

final readonly class PriceSuggestion
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public int $currentRp,
        public int $suggestedRp,
        public int $floorRp,
        public ?int $days,
        public array $reasons,
    ) {}

    public function lowersPrice(): bool
    {
        return $this->suggestedRp < $this->currentRp;
    }

    public function differenceRp(): int
    {
        return $this->currentRp - $this->suggestedRp;
    }
}
