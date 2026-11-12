<?php

namespace App\Notifications;

use App\Models\LiveSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The recording of a class is up, so anybody who missed it can catch up. */
class RecordingAvailable extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public LiveSession $session) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Recording: {$this->session->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("The recording of **{$this->session->title}** ({$this->session->scheduled_at->format('j M')}) is up.")
            ->action('Watch it', url('/student/classes'))
            ->line('It stays with the class, so you can come back to it any time.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'class.recording',
            'title' => "Recording up: {$this->session->title}",
            'url' => '/student/classes',
        ];
    }
}
