<?php

namespace App\Listeners;

use App\Mail\CampaignMail;
use App\Services\Mail\MailBudget;
use Illuminate\Mail\Events\MessageSent;
use Throwable;

/**
 * Every message the mailer hands over counts against the day's limit (X15),
 * once per recipient, because that is how the mail server counts.
 */
class CountMailSent
{
    public function handle(MessageSent $event): void
    {
        try {
            $recipients = count($event->sent->getEnvelope()->getRecipients());
        } catch (Throwable) {
            $recipients = 1;
        }

        try {
            MailBudget::recordSent($recipients, ($event->data['__laravel_mailable'] ?? null) === CampaignMail::class);
        } catch (Throwable $e) {
            // The message has gone. Failing here would fail its job, and the
            // retry would send it a second time.
            report($e);
        }
    }
}
