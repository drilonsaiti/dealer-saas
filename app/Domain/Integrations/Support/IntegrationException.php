<?php

namespace App\Domain\Integrations\Support;

use RuntimeException;

/**
 * An external service refused or failed; the message is shown to the dealer in the sync log.
 */
class IntegrationException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}
