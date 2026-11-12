<?php

namespace App\Notifications;

use App\Models\Registration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody registered this address for a course. Was it you?
 *
 * Nothing the person filling the form typed is repeated here, not even the
 * name: a field that ends up in somebody else's inbox is a way to send them
 * anything. The greeting uses the account's own name.
 */
class RegistrationToConfirm extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Registration $registration) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $course = $this->registration->form->course->title;

        return (new MailMessage)
            ->subject("Confirm your registration for {$course}")
            ->greeting("Hello {$notifiable->name},")
            ->line("This address was used to register for **{$course}** through one of our registration forms.")
            ->line('If it was you, confirm it from your account. Nothing happens until you do.')
            ->action('See the registration', url('/student/registrations'))
            ->line('If it was not you, choose "Not me" on that page, or ignore this message.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'registration.confirm',
            'title' => 'Confirm your registration for '.$this->registration->form->course->title,
            'url' => '/student/registrations',
        ];
    }
}
