<?php

namespace App\Notifications;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells whoever handles support that a ticket has arrived.
 *
 * Email only, and on purpose: the SLA clock is in hours, so a message that
 * wakes somebody at two in the morning buys nothing and costs goodwill.
 */
class TicketRaised extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SupportTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("[{$this->ticket->reference}] {$this->ticket->subject}")
            ->greeting('A support ticket has come in.')
            ->line("**{$this->ticket->client->name}** raised {$this->ticket->reference} at ".$this->ticket->priority.' priority.')
            ->line($this->ticket->subject)
            ->line(str($this->ticket->body)->limit(400)->toString());

        if ($this->ticket->response_due_at) {
            $message->line('The contract promises a first response by **'
                .$this->ticket->response_due_at->format('j M Y, g:i a').'**.');
        }

        return $message->action('Open the ticket', url("/admin/tickets/{$this->ticket->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'ticket.raised',
            'reference' => $this->ticket->reference,
            'subject' => $this->ticket->subject,
            'client' => $this->ticket->client->name,
            'url' => "/admin/tickets/{$this->ticket->id}",
        ];
    }
}
