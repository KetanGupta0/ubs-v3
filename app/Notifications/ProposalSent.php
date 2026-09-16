<?php

namespace App\Notifications;

use App\Models\Proposal;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProposalSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Proposal $proposal) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Proposal: '.$this->proposal->title)
            ->greeting("Hello {$notifiable->name},")
            ->line('We have put together a proposal for you.')
            ->line('**'.$this->proposal->title.'** ('.$this->proposal->number.')');

        if ($this->proposal->quotation) {
            $message->line('**Quoted:** '.Money::display($this->proposal->quotation->total).' including tax');
        }

        if ($this->proposal->valid_until) {
            $message->line('It holds until **'.$this->proposal->valid_until->format('j M Y').'**.');
        }

        return $message
            ->action('Read the proposal', url("/client/proposals/{$this->proposal->id}"))
            ->line('You can accept or decline it on that page, or reply to this email if anything needs changing first.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'proposal.sent',
            'number' => $this->proposal->number,
            'title' => $this->proposal->title,
            'url' => "/client/proposals/{$this->proposal->id}",
        ];
    }
}
