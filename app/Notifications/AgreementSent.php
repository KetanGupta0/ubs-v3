<?php

namespace App\Notifications;

use App\Models\Agreement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** An agreement has been sent to the client's signatories. */
class AgreementSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Agreement $agreement) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->agreement->kindLabel()} {$this->agreement->number}: {$this->agreement->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("We have sent you **{$this->agreement->title}** ({$this->agreement->kindLabel()}, {$this->agreement->number}).")
            ->line('You can read it and download the PDF from your account.')
            ->action('Read the agreement', url("/client/agreements/{$this->agreement->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'agreement.sent',
            'title' => "{$this->agreement->kindLabel()}: {$this->agreement->title}",
            'url' => "/client/agreements/{$this->agreement->id}",
        ];
    }
}
