<?php

use App\Http\Controllers\ClientErrorController;
use App\Models\User;
use App\Support\Monitoring\ErrorReports;
use App\Support\Monitoring\Health;
use Database\Seeders\PermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;

/*
 * Step I monitoring: /health for an uptime monitor, what an error report may
 * carry to Sentry, faults reported from the browser, and the dashboard saying
 * when the scheduler stopped.
 */

$heartbeat = fn () => storage_path('framework/scheduler-heartbeat');

afterEach(function () use ($heartbeat) {
    // The heartbeat is a real file in storage; a test must not leave one behind.
    @unlink($heartbeat());
});

it('answers ok for an uptime monitor when everything works, without a session', function () {
    $this->get('/health')->assertOk()
        ->assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'scheduler' => 'ok', 'queue' => 'ok', 'storage' => 'ok']])
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertCookieMissing(config('session.cookie'));
});

it('answers 503 naming the scheduler once it has not beaten for ten minutes, and in production when it never has', function () use ($heartbeat) {
    Health::beat();
    expect(is_file($heartbeat()))->toBeTrue();
    $this->get('/health')->assertOk();

    $this->travel(11)->minutes();
    $this->get('/health')->assertStatus(503)->assertJson(['status' => 'failing', 'checks' => ['scheduler' => 'late', 'database' => 'ok']]);

    unlink($heartbeat());
    $this->app['env'] = 'production';
    $this->get('/health')->assertStatus(503)->assertJsonPath('checks.scheduler', 'never_ran');
    $this->app['env'] = 'testing';

    // The scheduler is what beats.
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'health:heartbeat');
    expect($event?->expression)->toBe('* * * * *');
});

it('answers 503 when queued work has waited a quarter of an hour', function () {
    config(['queue.default' => 'database']);
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(20)->getTimestamp(), 'created_at' => now()->subMinutes(20)->getTimestamp()]);

    $this->get('/health')->assertStatus(503)->assertJsonPath('checks.queue', 'backed_up');
});

it('sends Sentry the fault and never the person', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.max_request_body_size'))->toBe('never')
        ->and(config('sentry.before_send'))->toBe([ErrorReports::class, 'beforeSend'])
        ->and(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse()
        ->and(config('sentry.traces_sample_rate'))->toBeNull();

    $query = new QueryException(
        'mysql',
        'insert into users (name, email) values (?, ?)',
        ['Meera Iyer', 'meera@example.com'],
        new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'meera@example.com' for key 'users_email_unique'", 23000),
    );
    $frames = new Stacktrace([new Frame('register', 'app/Services/Auth/Register.php', 12, null, null, ['email' => 'meera@example.com', 'password' => 'secret'])]);

    $event = Event::createEvent();
    $event->setExceptions([new ExceptionDataBag($query->getPrevious(), $frames), new ExceptionDataBag($query, $frames)]);
    $event->setRequest([
        'url' => 'https://unboundbyte.test/unsubscribe/bWVlcmFAZXhhbXBsZS5jb20?signature=abc123&email=meera@example.com',
        'method' => 'POST',
        'query_string' => 'signature=abc123&email=meera@example.com',
        'data' => ['password' => 'secret', 'code' => '123456'],
        'cookies' => ['ubs_session' => 'xyz'],
        'headers' => ['User-Agent' => ['Mozilla/5.0'], 'Cookie' => ['ubs_session=xyz'], 'Authorization' => ['Bearer abc'], 'X-Forwarded-For' => ['203.0.113.9']],
    ]);
    $event->setExtra(['note' => 'Call Meera on +91 98765 43210']);

    $sent = ErrorReports::beforeSend($event, EventHint::fromArray(['exception' => $query]));

    expect($sent->getRequest())->toBe([
        'method' => 'POST',
        'url' => 'https://unboundbyte.test/unsubscribe/[token]',
        'headers' => ['User-Agent' => ['Mozilla/5.0']],
    ])
        ->and($sent->getExceptions()[0]->getValue())->toBe("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '?' for key 'users_email_unique'")
        ->and($sent->getExceptions()[1]->getValue())->toBe('SQLSTATE[23000] on: insert into users (name, email) values (?, ?)')
        ->and($sent->getExceptions()[0]->getStacktrace()->getFrames()[0]->getVars())->toBe([])
        ->and($sent->getExtra())->toBe(['note' => 'Call Meera on [number]'])
        ->and($sent->getUser())->toBeNull();

    $crumb = ErrorReports::beforeBreadcrumb(new Breadcrumb('info', 'default', 'log', 'Sent the code to meera@example.com', ['to' => 'meera@example.com', 'context' => ['a' => 1]]));
    expect($crumb->getMessage())->toBe('Sent the code to [email]')
        ->and($crumb->getMetadata())->toBe(['to' => '[email]']);
});

it('takes faults from the browser, scrubbed, a few a minute per address and a few hundred a day', function () {
    Log::spy();

    $this->postJson('/client-errors', [
        'message' => 'Cannot read properties of undefined (reading \'email\') for meera@example.com',
        'stack' => "TypeError: x\n    at https://unboundbyte.test/build/assets/app.js:1:200",
        'where' => 'Dashboard (render function)',
        'page' => '/unsubscribe/bWVlcmFAZXhhbXBsZS5jb20?signature=abc',
    ])->assertNoContent();

    Log::shouldHaveReceived('warning')->once()->with(
        'In the browser: Cannot read properties of undefined (reading \'email\') for [email]',
        Mockery::on(fn (array $context) => $context['page'] === '/unsubscribe/[token]'
            && $context['where'] === 'Dashboard (render function)'
            && $context['role'] === 'visitor'),
    );

    $this->postJson('/client-errors', ['page' => '/'])->assertUnprocessable();

    foreach (range(1, 8) as $i) {
        $this->postJson('/client-errors', ['message' => "Fault {$i}"])->assertNoContent();
    }
    $this->postJson('/client-errors', ['message' => 'One too many'])->assertTooManyRequests();

    // Past the day's cap a report is accepted and dropped.
    Cache::put('client-errors:'.today()->toDateString(), ClientErrorController::DAILY_LIMIT, now()->endOfDay());
    $this->travel(2)->minutes();
    $this->postJson('/client-errors', ['message' => 'Over the cap'])->assertNoContent();
    // The first report and eight more; nothing for the one over the cap.
    Log::shouldHaveReceived('warning')->times(9);
});

it('tells the owner on the dashboard and in Settings when the scheduler stopped', function () {
    $this->seed(PermissionSeeder::class);
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get('/admin')->assertOk()
        ->assertInertia(fn ($page) => $page->where('attention', fn ($items) => collect($items)->doesntContain(fn ($item) => str_contains($item['title'], 'scheduler'))));

    Health::beat();
    $this->travel(30)->minutes();

    $this->actingAs($owner)->get('/admin')->assertOk()
        ->assertInertia(fn ($page) => $page->where('attention', fn ($items) => collect($items)->contains(fn ($item) => str_starts_with($item['title'], 'The scheduler has not run since')
            && $item['href'] === '/admin/settings?tab=providers')));

    $this->actingAs($owner)->get('/admin/settings')->assertOk()
        ->assertInertia(fn ($page) => $page->where('health.checks.scheduler', 'late')
            ->where('providers', fn ($providers) => collect($providers)->contains(fn ($provider) => $provider['name'] === 'Error tracking' && $provider['configured'] === false)));
});
