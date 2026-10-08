<?php

namespace App\Domain\Signatures\Support;

/**
 * Sends a short text message. The provider is connected later (concept: one-time codes by
 * SMS); until then the "log" driver writes the message to the application log.
 */
interface SmsSender
{
    public function send(string $phone, string $message): void;
}
