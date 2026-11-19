<?php

use App\Support\Monitoring\Health;
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
// Last month's service reports, drafted for a person to finish (CL17).
Schedule::command('service-reports:draft')->monthlyOn(1, '06:15');

// Shop orders left unpaid lapse, and free the codes they held (§4).
Schedule::command('shop:expire-orders')->dailyAt('03:40');

// Greetings and announcements go out a few at a time, inside a daily budget
// below the hosting's mail cap (§8, X8); once a day, unconfirmed festival
// dates are asked about.
Schedule::command('mail:send-promotional')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('mail:send-promotional', ['--remind'])->dailyAt('10:20');

// Once a quiz has closed for everybody and the last sitting is over, its
// attempts are compared (§9.1).
Schedule::command('quizzes:check-integrity')->everyFifteenMinutes()->withoutOverlapping(15);

// The AI's reading of what the checks flagged, through the Message Batches
// API: sent a few minutes after, read back once the batch has ended (§9.1).
Schedule::command('quizzes:ai-review')->everyFifteenMinutes()->withoutOverlapping(15);

// The website assistant's conversations go once older than the period set in the panel (§9.2).
Schedule::command('assistant:prune')->dailyAt('03:40');

/*
 * Hourly, so a report set for seven in the morning arrives at seven. Each
 * schedule decides for itself whether it is due.
 */
Schedule::command('reports:send')->hourly();

// Class and deadline reminders. Each goes once, whatever the schedule does.
Schedule::command('lms:remind')->everyFiveMinutes()->withoutOverlapping(10);

// A beat every minute, so /health and the dashboard can tell when the cron
// entry stopped running: the queue, mail and reminders all stop with it.
Schedule::call(fn () => Health::beat())->everyMinute()->name('health:heartbeat');

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
