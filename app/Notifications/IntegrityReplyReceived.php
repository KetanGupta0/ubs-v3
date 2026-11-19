<?php

namespace App\Notifications;

use App\Models\QuizIntegrityDecision;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A student replied to an integrity decision; the trainer who made it reads it and makes it final. */
class IntegrityReplyReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public QuizIntegrityDecision $decision) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $attempt = $this->decision->attempt;

        return (new MailMessage)
            ->subject("{$attempt->user->name} replied about {$attempt->quiz->title}")
            ->line("{$attempt->user->name} replied to your decision on attempt {$attempt->attempt_number}.")
            ->line('Read it, change the decision if it should change, and make it final.')
            ->action('Open the attempt', url($this->path()));
    }

    public function toArray(object $notifiable): array
    {
        $attempt = $this->decision->attempt;

        return [
            'kind' => 'quiz.integrity_reply',
            'title' => "{$attempt->user->name} replied about {$attempt->quiz->title}",
            'url' => $this->path(),
        ];
    }

    protected function path(): string
    {
        return "/admin/quiz-attempts/{$this->decision->quiz_attempt_id}/integrity";
    }
}
