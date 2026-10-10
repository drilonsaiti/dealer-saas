<?php

namespace App\Domain\Ai\Support;

/**
 * A language model behind an API. Throws AiException with a readable reason.
 */
interface TextGenerator
{
    public function generate(string $system, string $prompt, int $maxTokens = 1500): AiResult;

    public function model(): string;
}
