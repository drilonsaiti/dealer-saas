<?php

namespace App\Domain\Signatures\Support;

/**
 * Seals a finished PDF so any later change is detectable.
 */
interface DocumentSealer
{
    /**
     * Seals the PDF at $path in place.
     *
     * @return array<string, mixed>|null what was applied (method, certificate, timestamp), null when sealing is not available
     */
    public function seal(string $path): ?array;
}
