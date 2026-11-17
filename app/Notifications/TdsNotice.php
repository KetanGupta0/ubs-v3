<?php

namespace App\Notifications;

use App\Models\TdsDeduction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A Form 16A came in, for us to check; or did not match, for the client to correct (CL14). */
class TdsNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  string  $what  received or mismatch */
    public function __construct(public TdsDeduction $deduction, public string $what) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = $this->deduction->invoice;
        $mail = (new MailMessage)->subject($this->headline())->greeting("Hello {$notifiable->name},");

        if ($this->what === 'mismatch') {
            return $mail
                ->line("The Form 16A for invoice {$invoice->number} ({$this->deduction->amountLabel()} TDS, {$this->deduction->quarter}) does not match what Form 26AS shows us.")
                ->line('What we found: '.$this->deduction->check_note)
                ->line('Please check the return you filed and upload a corrected certificate.')
                ->action('Open the invoice', url("/client/payments/{$invoice->payment_request_id}"));
        }

        return $mail
            ->line(($this->deduction->client?->organisationName() ?? 'A client')." sent the Form 16A for invoice {$invoice->number}: {$this->deduction->amountLabel()} under {$this->deduction->sectionLabel()}, {$this->deduction->quarter}.")
            ->line('Check it against Form 26AS and mark it.')
            ->action('Open the TDS register', url('/admin/billing/tds'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => "tds.{$this->what}",
            'title' => $this->headline(),
            'url' => $this->what === 'mismatch' ? "/client/payments/{$this->deduction->invoice->payment_request_id}" : '/admin/billing/tds',
        ];
    }

    protected function headline(): string
    {
        return $this->what === 'mismatch'
            ? "Form 16A does not match: invoice {$this->deduction->invoice->number}"
            : "Form 16A received: invoice {$this->deduction->invoice->number}";
    }
}
