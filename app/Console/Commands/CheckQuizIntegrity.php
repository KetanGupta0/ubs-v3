<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Models\Quiz;
use App\Models\User;
use App\Notifications\IntegrityFlagsFound;
use App\Services\Lms\AiReview;
use App\Services\Lms\IntegrityChecks;
use App\Services\Lms\QuizGrader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Once a quiz has closed for everybody, compare what happened and what was
 * answered across the batch (§9.1, layer 2). Each quiz is checked once; a
 * trainer can run it again from the quiz's integrity page.
 */
class CheckQuizIntegrity extends Command
{
    protected $signature = 'quizzes:check-integrity';

    protected $description = 'Run the integrity checks on quizzes that have closed';

    public function handle(IntegrityChecks $checks, AiReview $ai): int
    {
        $flagged = 0;
        $quizzes = 0;

        Quiz::query()
            ->whereNotNull('closes_at')
            ->where('closes_at', '<', now())
            ->whereNull('integrity_checked_at')
            ->each(function (Quiz $quiz) use ($checks, $ai, &$flagged, &$quizzes) {
                // Somebody who started just before it closed is still sitting it.
                $lastEnds = $quiz->closes_at->copy()
                    ->addMinutes((int) $quiz->time_limit_minutes)
                    ->addSeconds(QuizGrader::GRACE_SECONDS);

                if ($lastEnds->isFuture()) {
                    return;
                }

                $found = $checks->run($quiz);
                $flagged += $found;
                $quizzes++;

                if ($found > 0) {
                    // An AI reads each one before a person does (layer 3), and
                    // the trainers are told there is something to look at.
                    $ai->queueFlagged($quiz);
                    $this->tellTrainers($quiz);
                }
            });

        $this->info("Checked {$quizzes} ".str('quiz')->plural($quizzes).", {$flagged} ".str('attempt')->plural($flagged).' to look at.');

        return self::SUCCESS;
    }

    /** The trainers of the batches whose attempts were flagged; the owner where a batch has none. */
    protected function tellTrainers(Quiz $quiz): void
    {
        $batches = $quiz->attempts()->whereNotNull('flagged_at')->get(['batch_id', 'flags']);
        $count = $batches->count();
        $trainers = Batch::query()->whereKey($batches->pluck('batch_id')->filter()->unique())->with('trainer')->get()->pluck('trainer')->filter();

        if ($trainers->isEmpty() || $batches->contains(fn ($attempt) => $attempt->batch_id === null)) {
            $trainers->push(User::query()->where('is_owner', true)->first());
        }

        Notification::send($trainers->filter()->unique('id'), new IntegrityFlagsFound($quiz, $count));
    }
}
