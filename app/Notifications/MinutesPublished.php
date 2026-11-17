<?php

namespace App\Notifications;

use App\Models\MeetingMinutes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The minutes of a meeting are ready for the client to acknowledge (CL11). */
class MinutesPublished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public MeetingMinutes $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $meeting = $this->minutes->meeting;

        return (new MailMessage)
            ->subject("Minutes: {$meeting->title}".($this->minutes->version > 1 ? " (version {$this->minutes->version})" : ''))
            ->greeting("Hello {$notifiable->name},")
            ->line("The minutes of **{$meeting->title}** on {$meeting->starts_at->format('j M Y')} are ready.")
            ->line('Please read them and acknowledge them, or tell us what is not right, so we both work from the same record.')
            ->action('Read the minutes', url("/client/meetings/{$meeting->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'meeting.minutes_published',
            'title' => "Minutes to read: {$this->minutes->meeting->title}",
            'url' => "/client/meetings/{$this->minutes->meeting_id}",
        ];
    }
}
