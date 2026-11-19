<?php

use App\Support\Monitoring\Health;
use Database\Seeders\PermissionSeeder;

/*
 * Step I: php artisan app:preflight, run after every deploy, fails on what is
 * unsafe in production and warns on what the owner has yet to set up.
 */

afterEach(fn () => @unlink(storage_path('framework/scheduler-heartbeat')));

it('fails an install that is not set up for production, saying what to fix', function () {
    $this->artisan('app:preflight')
        ->expectsOutputToContain('Environment is production')
        ->expectsOutputToContain('Fix the failures before the site takes traffic.')
        ->expectsOutputToContain('Every permission is seeded')
        ->assertFailed();
});

it('passes an install set up for production, with warnings for what is still to come', function () {
    $this->seed(PermissionSeeder::class);
    Health::beat();

    $this->app['env'] = 'production';
    config([
        'app.debug' => false,
        'app.url' => 'https://unboundbyte.com',
        'session.secure' => true,
        'queue.default' => 'database',
        'queue.from_scheduler' => true,
        'mail.default' => 'smtp',
        'mail.from.address' => 'hello@unboundbyte.com',
        'sentry.dsn' => null,
    ]);

    $this->artisan('app:preflight')
        ->expectsOutputToContain('Mail limit: 100 a day, 40 kept for service mail')
        ->expectsOutputToContain('Set SENTRY_LARAVEL_DSN')
        ->expectsOutputToContain('Ready, with')
        ->assertSuccessful();

    $this->app['env'] = 'testing';
});
