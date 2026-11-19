<?php

namespace App\Console\Commands;

use App\Models\Quiz;
use App\Services\Lms\IntegrityChecks;
use App\Services\Lms\QuizGrader;
use Illuminate\Console\Command;

/**
 * Once a quiz has closed for everybody, compare what happened and what was
 * answered across the batch (§9.1, layer 2). Each quiz is checked once; a
 * trainer can run it again from the quiz's integrity page.
 */
class CheckQuizIntegrity extends Command
{
    protected $signature = 'quizzes:check-integrity';

    protected $description = 'Run the integrity checks on quizzes that have closed';

    public function handle(IntegrityChecks $checks): int
    {
        $flagged = 0;
        $quizzes = 0;

        Quiz::query()
            ->whereNotNull('closes_at')
            ->where('closes_at', '<', now())
            ->whereNull('integrity_checked_at')
            ->each(function (Quiz $quiz) use ($checks, &$flagged, &$quizzes) {
                // Somebody who started just before it closed is still sitting it.
                $lastEnds = $quiz->closes_at->copy()
                    ->addMinutes((int) $quiz->time_limit_minutes)
                    ->addSeconds(QuizGrader::GRACE_SECONDS);

                if ($lastEnds->isFuture()) {
                    return;
                }

                $flagged += $checks->run($quiz);
                $quizzes++;
            });

        $this->info("Checked {$quizzes} ".str('quiz')->plural($quizzes).", {$flagged} ".str('attempt')->plural($flagged).' to look at.');

        return self::SUCCESS;
    }
}
