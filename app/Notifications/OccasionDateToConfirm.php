<?php

namespace App\Notifications;

use App\Models\Occasion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A festival's date this year is still a guess, a month before it (§8). */
class OccasionDateToConfirm extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Occasion $occasion) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Confirm the date of {$this->occasion->name}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->occasion->name} is set for {$this->occasion->date->format('j F Y')}, worked out from last year. Festival dates move, so its greeting will not go until somebody confirms the date or corrects it.")
            ->action('Confirm the date', url('/admin/mail/occasions'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'mail.occasion_unconfirmed',
            'title' => "Confirm the date of {$this->occasion->name}",
            'url' => '/admin/mail/occasions',
        ];
    }
}
