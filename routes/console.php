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
