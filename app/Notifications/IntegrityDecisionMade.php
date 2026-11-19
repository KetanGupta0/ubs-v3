<?php

namespace App\Notifications;

use App\Models\QuizIntegrityDecision;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * What a trainer decided about one of a student's quiz attempts (§9.1). It
 * says what was decided and why in plain words, never "suspected of
 * cheating", and that they can reply once before it is final.
 */
class IntegrityDecisionMade extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public QuizIntegrityDecision $decision, public bool $final = false) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->opening());

        if ($this->decision->reason) {
            $message->line('**Why:** '.$this->decision->reason);
        }

        $message->line($this->final
            ? 'Your reply was read and this is now final.'
            : 'If something was different from how it looks, you can reply once, from the attempt, before this is final.');

        return $message->action('See your attempt', url($this->path()));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'quiz.integrity_decision',
            'title' => $this->title(),
            'outcome' => $this->decision->outcome,
            'url' => $this->path(),
        ];
    }

    protected function title(): string
    {
        $quiz = $this->decision->attempt->quiz->title;

        return match ($this->decision->outcome) {
            'discuss' => "Your trainer would like to talk about {$quiz}",
            'voided' => "Your attempt at {$quiz} does not count",
            'cleared' => "Your attempt at {$quiz} stands",
            default => "About your attempt at {$quiz}",
        }.($this->final ? ' (final)' : '');
    }

    protected function opening(): string
    {
        $attempt = $this->decision->attempt;

        return match ($this->decision->outcome) {
            'discuss' => "Your trainer looked at attempt {$attempt->attempt_number} and would like to talk to you about it before anything else happens.",
            'voided' => "Your trainer looked at attempt {$attempt->attempt_number} and decided it does not count."
                .($this->decision->resit ? ' You can sit the quiz again.' : ''),
            'cleared' => "Your trainer looked at attempt {$attempt->attempt_number} again, and it stands as it is.",
            default => "Your trainer looked at attempt {$attempt->attempt_number}.",
        };
    }

    protected function path(): string
    {
        $attempt = $this->decision->attempt;

        return "/student/quizzes/{$attempt->quiz_id}/attempts/{$attempt->id}/review";
    }
}
