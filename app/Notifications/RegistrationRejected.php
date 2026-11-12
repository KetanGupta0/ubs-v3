<?php

namespace App\Notifications;

use App\Models\Registration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A registration was turned down, with the reason when the team gave one. */
class RegistrationRejected extends Notification implements ShouldQueue
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

        $message = (new MailMessage)
            ->subject("Your registration for {$course}")
            ->greeting("Hello {$notifiable->name},")
            ->line("We are not able to offer you a place on **{$course}** this time.");

        if (filled($this->registration->note)) {
            $message->line($this->registration->note);
        }

        return $message
            ->line('If you have a question about it, reply from your account and somebody will answer.')
            ->action('Ask a question', url('/student/support/new'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'registration.rejected',
            'title' => 'Your registration for '.$this->registration->form->course->title,
            'url' => '/student/registrations',
        ];
    }
}
