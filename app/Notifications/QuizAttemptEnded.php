<?php

namespace App\Notifications;

use App\Models\QuizAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A proctor ended a student's attempt (§9.1a). They are told why, that what
 * they had answered was marked, and that a resit is a trainer's decision they
 * can ask about, rather than finding a closed quiz and guessing.
 */
class QuizAttemptEnded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public QuizAttempt $attempt) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your attempt at '.$this->attempt->quiz->title.' was ended')
            ->greeting("Hello {$notifiable->name},")
            ->line('The member of staff watching '.$this->attempt->quiz->title.' ended your attempt.')
            ->line('**Why:** '.$this->attempt->ended_reason)
            ->line('What you had answered was marked. Your trainer looks at what happened and decides whether you can sit it again; if something went wrong on your side, reply and tell us.')
            ->action('See your attempt', url($this->path()));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'quiz.attempt_ended',
            'title' => 'Your attempt at '.$this->attempt->quiz->title.' was ended',
            'reason' => $this->attempt->ended_reason,
            'url' => $this->path(),
        ];
    }

    protected function path(): string
    {
        return "/student/quizzes/{$this->attempt->quiz_id}/attempts/{$this->attempt->id}/review";
    }
}
