<?php

namespace App\Domain\Signatures\Support;

use Illuminate\Support\Facades\Log;

class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::info('SMS (log driver, not sent)', ['to' => $phone, 'message' => $message]);
    }
}
