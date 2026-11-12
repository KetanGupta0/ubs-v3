<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Once a day, in the morning, so a renewal warning lands while somebody is at
 * their desk and can do something about it.
 */
Schedule::command('renewals:remind')->dailyAt('09:00');

/*
 * Hourly, so a report set for seven in the morning arrives at seven. Each
 * schedule decides for itself whether it is due.
 */
Schedule::command('reports:send')->hourly();

// Class and deadline reminders. Each goes once, whatever the schedule does.
Schedule::command('lms:remind')->everyFiveMinutes()->withoutOverlapping(10);

/*
 * Shared hosting has no long running worker, so the queue is drained here,
 * from the one-minute cron that runs the scheduler. It stops when the queue
 * is empty or after 50 seconds, so one run has finished before the next
 * starts, and jobs delayed by a minute (such as a bulk credential send) go
 * out on the minute they are due. `retry_after` (90 seconds) is longer than
 * a run, so a job is never picked up twice.
 */
Schedule::command('queue:work', ['--stop-when-empty', '--max-time=50', '--tries=3', '--sleep=1'])
    ->everyMinute()
    ->withoutOverlapping(2)
    ->when(fn () => config('queue.from_scheduler'));
