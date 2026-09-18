<?php

use App\Enums\Role;
use App\Mail\ScheduledReport;
use App\Models\Permission;
use App\Models\ReportSchedule;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();

    $this->seed(PermissionSeeder::class);

    Setting::put('company.state', 'Madhya Pradesh', 'company');

    $this->owner = User::factory()->owner()->create();
});

function scheduleFor(User $user, array $overrides = []): ReportSchedule
{
    return ReportSchedule::query()->create([
        'user_id' => $user->id,
        'report' => 'revenue',
        'name' => 'Revenue',
        'cadence' => 'monthly',
        'day' => 1,
        'hour' => 7,
        'recipients' => ['accounts@example.test'],
        'format' => 'csv',
        ...$overrides,
    ]);
}

it('schedules a report from the screen', function () {
    $this->actingAs($this->owner)->post('/admin/reports/revenue/schedule', [
        'cadence' => 'monthly',
        'day' => 1,
        'hour' => 7,
        'recipients' => ['accounts@example.test', 'accounts@example.test'],
        'format' => 'pdf',
    ])->assertRedirect();

    $schedule = ReportSchedule::query()->sole();

    // The same address twice is somebody being careful, not two recipients.
    expect($schedule->recipients)->toBe(['accounts@example.test'])
        ->and($schedule->report)->toBe('revenue');
});

it('refuses an address that is not one', function () {
    $this->actingAs($this->owner)->post('/admin/reports/revenue/schedule', [
        'cadence' => 'monthly',
        'hour' => 7,
        'recipients' => ['not-an-address'],
        'format' => 'pdf',
    ])->assertSessionHasErrors('recipients.0');

    expect(ReportSchedule::query()->count())->toBe(0);
});

it('sends what is due and nothing else', function () {
    $due = scheduleFor($this->owner, ['day' => now()->day, 'hour' => 0]);
    $notDue = scheduleFor($this->owner, ['day' => now()->addDays(3)->day ?: 28, 'hour' => 0, 'report' => 'receivables']);

    $this->artisan('reports:send')->assertSuccessful();

    Mail::assertSent(ScheduledReport::class, 1);

    expect($due->fresh()->last_sent_at)->not->toBeNull()
        ->and($notDue->fresh()->last_sent_at)->toBeNull();
});

it('does not send the same period twice in one run of the clock', function () {
    scheduleFor($this->owner, ['day' => now()->day, 'hour' => 0]);

    $this->artisan('reports:send');
    $this->artisan('reports:send');

    Mail::assertSent(ScheduledReport::class, 1);
});

it('stops sending when the person who scheduled it loses access', function () {
    $staff = User::factory()->create(['role' => Role::Admin]);
    $staff->permissions()->sync(Permission::query()->where('key', 'billing.view')->pluck('id'));

    $schedule = scheduleFor($staff, ['day' => now()->day, 'hour' => 0]);

    // A monthly email is exactly the thing nobody remembers to cancel when
    // somebody moves off the accounts team.
    $staff->permissions()->sync([]);

    $this->artisan('reports:send');

    Mail::assertNothingSent();

    expect($schedule->fresh()->last_error)->toContain('no longer has access');
});

it('records a failure on the schedule rather than swallowing it', function () {
    $schedule = scheduleFor($this->owner, ['day' => now()->day, 'hour' => 0, 'report' => 'no-such-report']);

    $this->artisan('reports:send')->assertSuccessful();

    expect($schedule->fresh()->last_failed_at)->not->toBeNull()
        ->and($schedule->fresh()->last_error)->toContain('no report called');
});

it('only lets somebody stop their own schedule', function () {
    $mine = scheduleFor($this->owner);
    $theirs = scheduleFor(User::factory()->owner()->create());

    $this->actingAs($this->owner)->delete("/admin/reports/schedules/{$theirs->id}")->assertNotFound();
    $this->actingAs($this->owner)->delete("/admin/reports/schedules/{$mine->id}")->assertRedirect();

    expect(ReportSchedule::query()->count())->toBe(1);
});

it('knows what is due', function () {
    $daily = scheduleFor($this->owner, ['cadence' => 'daily', 'hour' => 0]);

    expect($daily->isDue())->toBeTrue();

    $daily->forceFill(['last_sent_at' => now()])->save();

    expect($daily->fresh()->isDue())->toBeFalse()
        ->and($daily->fresh()->isDue(now()->addDay()))->toBeTrue();
});
