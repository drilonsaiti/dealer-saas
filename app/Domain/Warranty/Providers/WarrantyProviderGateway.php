<?php

namespace App\Domain\Warranty\Providers;

use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Models\WarrantyClaim;

/**
 * How a warranty provider receives registrations and claims. Today by e-mail (NSA and
 * MultiPart offer no public API); an API adapter can replace it per product later.
 */
interface WarrantyProviderGateway
{
    /** Returns a short text for the user (what happened, what to do next). */
    public function submit(Warranty $warranty): string;

    public function report(WarrantyClaim $claim): string;
}
