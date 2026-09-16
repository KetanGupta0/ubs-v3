<?php

namespace App\Services\Sms;

/**
 * Sending an SMS.
 *
 * An interface rather than a direct MSG91 call, because every environment
 * except production should be writing to a log instead of spending money and
 * messaging real phone numbers.
 */
interface SmsSender
{
    /**
     * @param  string  $to  Mobile number in E.164 form, for example +919876543210.
     * @param  array<string, mixed>  $context  Driver specific extras, such as a template id.
     */
    public function send(string $to, string $message, array $context = []): void;
}
