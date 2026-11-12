<?php

namespace App\Notifications;

use App\Models\LessonDoubt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The question a student asked on a lesson has an answer. */
class DoubtAnswered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public LessonDoubt $doubt) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    protected function path(): string
    {
        return "/student/courses/{$this->doubt->course_id}/lessons/{$this->doubt->lesson_id}#doubt-{$this->doubt->id}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your question on '.$this->doubt->lesson->title.' has an answer')
            ->greeting("Hello {$notifiable->name},")
            ->line('Your question on **'.$this->doubt->lesson->title.'** has been answered.')
            ->action('Read the answer', url($this->path()));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'doubt.answered',
            'title' => 'Answer on '.$this->doubt->lesson->title,
            'url' => $this->path(),
        ];
    }
}
