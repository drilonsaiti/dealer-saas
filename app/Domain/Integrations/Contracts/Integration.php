<?php

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\Models\IntegrationAccount;

/**
 * Any external service a dealer connects with own credentials.
 */
interface Integration
{
    public function key(): string;

    /** Checks the credentials; throws IntegrationException with a readable reason. */
    public function test(IntegrationAccount $account): void;
}
