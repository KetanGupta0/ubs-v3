<?php

namespace App\Notifications;

use App\Models\Quiz;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * The checks on a closed quiz found attempts worth a look (§9.1). Sent to the
 * trainers of the batches concerned, in the platform only: it is a prompt to
 * look, never a verdict, and the details stay on the screen it opens.
 */
class IntegrityFlagsFound extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Quiz $quiz, public int $flagged) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'quiz.integrity_flags',
            'title' => "{$this->flagged} ".($this->flagged === 1 ? 'attempt' : 'attempts')." at {$this->quiz->title} to look at",
            'url' => "/admin/courses/{$this->quiz->course_id}/quizzes/{$this->quiz->id}/integrity",
        ];
    }
}
