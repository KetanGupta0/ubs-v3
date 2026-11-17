<?php

namespace App\Notifications;

use App\Models\MeetingMinutes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The client acknowledged a meeting's minutes, or said what is wrong (CL11). */
class MinutesAnswered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public MeetingMinutes $minutes) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->headline())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->headline().'.');

        if ($this->minutes->dispute_note) {
            $mail->line('What they said: '.$this->minutes->dispute_note)
                ->line('Write the next version of the minutes with the correction, and publish it for them to acknowledge.');
        }

        return $mail->action('Open the meeting', url("/admin/meetings/{$this->minutes->meeting_id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->minutes->disputed_at ? 'meeting.minutes_disputed' : 'meeting.minutes_acknowledged',
            'title' => $this->headline(),
            'url' => "/admin/meetings/{$this->minutes->meeting_id}",
        ];
    }

    protected function headline(): string
    {
        $meeting = $this->minutes->meeting;
        $who = $meeting->client?->organisationName() ?? 'The client';

        return $this->minutes->disputed_at
            ? "{$who} says the minutes of {$meeting->title} are not right"
            : "{$who} acknowledged the minutes of {$meeting->title}";
    }
}
