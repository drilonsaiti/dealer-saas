<?php

namespace App\Domain\Ai\Support;

final readonly class AiResult
{
    public function __construct(
        public string $text,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}
}
