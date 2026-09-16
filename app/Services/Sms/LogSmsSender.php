<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Writes the message to the log instead of sending it.
 *
 * The default everywhere except production, so a developer can read the code
 * they just requested out of the log file.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message, array $context = []): void
    {
        Log::channel(config('services.sms.log_channel', 'stack'))
            ->info('SMS', ['to' => $to, 'message' => $message, ...$context]);
    }
}
