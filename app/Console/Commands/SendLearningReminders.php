<?php

namespace App\Console\Commands;

use App\Services\Lms\Reminders;
use Illuminate\Console\Command;

/** Class and deadline reminders. Scheduled every five minutes; safe to run more often. */
class SendLearningReminders extends Command
{
    protected $signature = 'lms:remind';

    protected $description = 'Send class and deadline reminders that are due, once each';

    public function handle(Reminders $reminders): int
    {
        $sent = $reminders->run();

        $this->info(sprintf(
            'Reminders sent: %d for tomorrow\'s classes, %d for classes within the hour, %d for work closing.',
            $sent['class_day'],
            $sent['class_hour'],
            $sent['deadline'],
        ));

        return self::SUCCESS;
    }
}
