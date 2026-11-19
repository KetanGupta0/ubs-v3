<?php

namespace App\Listeners;

use App\Services\Mail\MailBudget;
use Illuminate\Queue\Events\JobFailed;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * A queued message the mail server would not take, once its retries are
 * spent. Near the day's limit that usually means the plan's cap was reached,
 * which the dashboard says (X15).
 */
class CountMailRefused
{
    public function handle(JobFailed $event): void
    {
        if (! $event->exception instanceof TransportExceptionInterface) {
            return;
        }

        try {
            MailBudget::recordRefused();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
