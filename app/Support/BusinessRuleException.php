<?php

namespace App\Support;

use DomainException;

/**
 * A business rule said no (wrong status, missing data, duplicate). The message is already
 * translated and meant for the user; Filament actions show it as a notification.
 */
class BusinessRuleException extends DomainException
{
    /**
     * @param  list<string>  $reasons
     */
    public static function because(array $reasons): self
    {
        return new self(implode(' ', $reasons));
    }
}
