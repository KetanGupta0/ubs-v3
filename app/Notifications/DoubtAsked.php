<?php

namespace App\Notifications;

use App\Models\LessonDoubt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A student on the trainer's batch asked a question on a lesson. */
class DoubtAsked extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public LessonDoubt $doubt, public bool $followUp = false) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lesson = $this->doubt->lesson->title;

        return (new MailMessage)
            ->subject(($this->followUp ? 'Follow-up question on ' : 'Question on ').$lesson)
            ->greeting("Hello {$notifiable->name},")
            ->line(($this->followUp ? 'A student added to their question' : 'A student on '.($this->doubt->batch?->name ?? 'your batch').' asked a question').' on **'.$lesson.'**.')
            ->action('Read and answer', url("/admin/doubts/{$this->doubt->id}"))
            ->line('The answer is shown to everybody on the batch.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'doubt.asked',
            'title' => 'Question on '.$this->doubt->lesson->title,
            'url' => "/admin/doubts/{$this->doubt->id}",
        ];
    }
}
