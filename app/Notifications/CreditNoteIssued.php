<?php

namespace App\Notifications;

use App\Models\CreditNote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A credit note against one of the client's invoices (CL14). */
class CreditNoteIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public CreditNote $note) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Credit note {$this->note->number} against invoice {$this->note->invoice->number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("We have issued credit note **{$this->note->number}** for {$this->note->totalLabel()} against invoice {$this->note->invoice->number}.")
            ->line('Reason: '.$this->note->reason)
            ->action('See the invoice', url("/client/invoices/{$this->note->invoice_id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'credit_note.issued',
            'title' => "Credit note {$this->note->number}: {$this->note->totalLabel()}",
            'url' => "/client/invoices/{$this->note->invoice_id}",
        ];
    }
}
