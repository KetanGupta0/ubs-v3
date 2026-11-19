<?php

use App\Enums\Role;
use App\Mail\CampaignMail;
use App\Models\MailCampaign;
use App\Models\Occasion;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\CampaignPosted;
use App\Notifications\OccasionDateToConfirm;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    $this->seed(PermissionSeeder::class);
    $this->travelTo(Carbon::parse('2026-11-20 07:00'));

    $this->owner = User::factory()->owner()->create();
    $this->editor = User::factory()->admin()->create();
    $this->editor->permissions()->attach(Permission::query()->where('key', 'mail.occasions')->get());
    $this->student = User::factory()->create(['role' => Role::Student, 'name' => 'Meera Iyer']);

    $this->day = fn (array $fields = []) => $this->actingAs($this->editor)->post('/admin/mail/occasions', [
        'name' => 'Guru Nanak Jayanti', 'date' => '2026-11-24', 'repeats' => 'confirm_yearly', 'date_confirmed' => true,
        'send_time' => '08:00', 'audience' => ['students'], 'subject' => 'Warm wishes, {first_name}',
        'message' => 'Wishing you peace on Gurpurab.', 'is_active' => true, ...$fields,
    ]);
});

it('seeds the national days, switched off, with their own dates', function () {
    $days = Occasion::query()->orderBy('date')->get();

    expect($days->pluck('name')->all())->toContain('Republic Day', 'Independence Day', 'Gandhi Jayanti')
        ->and($days->every(fn (Occasion $occasion) => ! $occasion->is_active && $occasion->repeats === 'same_date' && $occasion->date_confirmed))->toBeTrue();

    $this->actingAs($this->editor)->get('/admin/mail/occasions')->assertOk()->assertInertia(fn ($page) => $page->component('admin/mail/Occasions')->has('occasions', 3));
    $this->actingAs(User::factory()->admin()->create())->get('/admin/mail/occasions')->assertForbidden();
});

it('greets on the day, from its time, and moves a festival on to a date somebody must confirm', function () {
    ($this->day)()->assertSessionHas('success');
    $occasion = Occasion::query()->where('name', 'Guru Nanak Jayanti')->sole();

    $this->travelTo(Carbon::parse('2026-11-24 07:55'));
    $this->artisan('mail:send-promotional');
    expect(MailCampaign::query()->count())->toBe(0);

    $this->travelTo(Carbon::parse('2026-11-24 08:05'));
    $this->artisan('mail:send-promotional');

    $campaign = MailCampaign::query()->sole();
    expect($campaign->kind)->toBe('greeting')
        ->and($campaign->expires_at->toDateTimeString())->toBe('2026-11-24 23:59:59');
    Notification::assertSentTo($this->student, CampaignPosted::class);
    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->hasTo($this->student->email) && $mail->envelope()->subject === 'Warm wishes, Meera');

    // Once a year: next year's date is a guess until confirmed.
    $occasion->refresh();
    expect($occasion->date->toDateString())->toBe('2027-11-24')
        ->and($occasion->date_confirmed)->toBeFalse()
        ->and($occasion->last_sent_on->toDateString())->toBe('2026-11-24');
    $this->artisan('mail:send-promotional');
    expect(MailCampaign::query()->count())->toBe(1);
});

it('never sends a date nobody confirmed, or a greeting late', function () {
    ($this->day)(['date_confirmed' => false]);
    $occasion = Occasion::query()->where('name', 'Guru Nanak Jayanti')->sole();

    $this->travelTo(Carbon::parse('2026-11-24 09:00'));
    $this->artisan('mail:send-promotional');
    expect(MailCampaign::query()->count())->toBe(0);

    // The day passed unconfirmed: it moves on, and was not sent.
    $this->travelTo(Carbon::parse('2026-11-25 09:00'));
    $this->artisan('mail:send-promotional');
    expect(MailCampaign::query()->count())->toBe(0)
        ->and($occasion->fresh()->date->toDateString())->toBe('2027-11-24');

    // A confirmed day the scheduler missed is skipped too, never sent the day after.
    ($this->day)(['name' => 'Missed', 'date' => '2026-11-26']);
    $this->travelTo(Carbon::parse('2026-11-27 09:00'));
    $this->artisan('mail:send-promotional');
    expect(MailCampaign::query()->count())->toBe(0);
});

it('stops a greeting at the end of its day, whatever the budget left unsent', function () {
    config(['company.mail.daily_limit' => 11, 'company.mail.service_reserve' => 10]);
    User::factory()->create(['role' => Role::Student]);
    ($this->day)();

    $this->travelTo(Carbon::parse('2026-11-24 08:05'));
    $this->artisan('mail:send-promotional');
    Mail::assertSent(CampaignMail::class, 1);

    $this->travelTo(Carbon::parse('2026-11-25 00:05'));
    $this->artisan('mail:send-promotional');

    $campaign = MailCampaign::query()->sole();
    expect($campaign->status)->toBe('sent')
        ->and($campaign->counts())->toMatchArray(['sent' => 1, 'skipped' => 1]);
    Mail::assertSent(CampaignMail::class, 1);
});

it('asks a month ahead about a festival date nobody has confirmed, once', function () {
    ($this->day)(['date' => '2026-12-15', 'date_confirmed' => false]);

    $this->artisan('mail:send-promotional', ['--remind' => true]);
    $this->artisan('mail:send-promotional', ['--remind' => true]);

    Notification::assertSentToTimes($this->editor, OccasionDateToConfirm::class, 1);
    Notification::assertSentTo($this->owner, OccasionDateToConfirm::class);

    // Confirming it is a click on the list.
    $occasion = Occasion::query()->where('name', 'Guru Nanak Jayanti')->sole();
    $this->actingAs($this->editor)->put("/admin/mail/occasions/{$occasion->id}", [
        'name' => $occasion->name, 'date' => '2026-12-15', 'repeats' => 'confirm_yearly', 'date_confirmed' => true, 'send_time' => '08:00',
        'audience' => ['students'], 'subject' => $occasion->subject, 'message' => $occasion->message, 'is_active' => true,
    ])->assertSessionHas('success');
    expect($occasion->fresh()->date_confirmed)->toBeTrue();
});

it('keeps a day that was sent on record, and previews and tests its greeting on the editor', function () {
    ($this->day)();
    $occasion = Occasion::query()->where('name', 'Guru Nanak Jayanti')->sole();

    expect($this->actingAs($this->editor)->get("/admin/mail/occasions/{$occasion->id}/preview")->assertOk()->getContent())->toContain('Wishing you peace');
    $this->actingAs($this->editor)->post("/admin/mail/occasions/{$occasion->id}/test")->assertSessionHas('success');
    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->hasTo($this->editor->email));

    $this->travelTo(Carbon::parse('2026-11-24 08:05'));
    $this->artisan('mail:send-promotional');
    $this->actingAs($this->editor)->delete("/admin/mail/occasions/{$occasion->id}")->assertSessionHas('error');
    expect(Occasion::query()->whereKey($occasion->id)->exists())->toBeTrue();
});
