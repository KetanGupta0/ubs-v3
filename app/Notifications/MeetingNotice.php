<?php

namespace App\Notifications;

use App\Models\Meeting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A meeting with the client was set up, moved or called off (CL11). */
class MeetingNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  string  $what  scheduled, changed or cancelled */
    public function __construct(public Meeting $meeting, public string $what) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->headline())
            ->greeting("Hello {$notifiable->name},");

        if ($this->what === 'cancelled') {
            $mail->line("We have called off **{$this->meeting->title}**, which was set for {$this->meeting->whenLabel()} (India time).");

            if ($this->meeting->cancel_reason) {
                $mail->line($this->meeting->cancel_reason);
            }

            return $mail->action('Open your meetings', url('/client/meetings'));
        }

        $mail->line(($this->what === 'changed' ? 'The details have changed for ' : 'We have set up ')."**{$this->meeting->title}**.")
            ->line("When: {$this->meeting->whenLabel()} (India time).");

        if ($this->meeting->location) {
            $mail->line("Where: {$this->meeting->location}");
        }

        if ($this->meeting->link) {
            $mail->line("Join: {$this->meeting->link}");
        }

        return $mail->action('See the details', url("/client/meetings/{$this->meeting->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => "meeting.{$this->what}",
            'title' => $this->headline(),
            'url' => $this->what === 'cancelled' ? '/client/meetings' : "/client/meetings/{$this->meeting->id}",
        ];
    }

    protected function headline(): string
    {
        return match ($this->what) {
            'cancelled' => "Called off: {$this->meeting->title}",
            'changed' => "Changed: {$this->meeting->title}, {$this->meeting->starts_at->format('D j M, g:i a')}",
            default => "Meeting: {$this->meeting->title}, {$this->meeting->starts_at->format('D j M, g:i a')}",
        };
    }
}
