<?php

namespace App\Console\Commands;

use App\Services\Lms\AiReview;
use Illuminate\Console\Command;

/**
 * The AI's side of quiz integrity (§9.1, layer 3), from the scheduler: send
 * what is waiting as one batch, and read back batches that have ended. Does
 * nothing until the Claude API key is set.
 */
class ReviewQuizAttempts extends Command
{
    protected $signature = 'quizzes:ai-review';

    protected $description = 'Send flagged quiz attempts for AI review and read back the answers';

    public function handle(AiReview $ai): int
    {
        if (! $ai->available()) {
            $this->info('The Claude API key is not set, so nothing was sent.');

            return self::SUCCESS;
        }

        $finished = $ai->collect();
        $sent = $ai->send();

        $this->info("Sent {$sent} for review, {$finished} reviews back.");

        return self::SUCCESS;
    }
}
