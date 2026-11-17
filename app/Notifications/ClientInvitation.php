<?php

namespace App\Notifications;

use App\Models\ClientMember;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** An existing client account is invited into an organisation, and has to say yes. */
class ClientInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ClientMember $membership) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organisation = $this->membership->account->organisationName();

        return (new MailMessage)
            ->subject("You are invited to join {$organisation}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->membership->inviter?->name} invited you to join **{$organisation}** as ".mb_strtolower($this->membership->roleLabel()).'.')
            ->line('Joining shows you its projects, documents and invoices. Nothing changes until you accept.')
            ->action('See the invitation', url('/client'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'client.invitation',
            'title' => 'Invitation to join '.$this->membership->account->organisationName(),
            'url' => '/client',
        ];
    }
}
