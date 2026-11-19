<?php

use App\Models\AuditLog;
use App\Models\MailCampaign;
use App\Models\Permission;
use App\Models\User;
use App\Services\Mail\Campaigns;
use App\Services\Mail\MailBudget;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Exception\TransportException;

/*
 * The day's mail against what the hosting plan allows (X15): set in Settings,
 * counted as the mailer hands each message over, shown on the dashboard, and
 * what greetings and announcements may use.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
});

function serviceMail(string ...$cc): void
{
    Mail::raw('Your sign in code is 123456.', function ($message) use ($cc) {
        $message->to('meera@example.com')->subject('Your code');

        foreach ($cc as $address) {
            $message->cc($address);
        }
    });
}

it('counts every message the mailer hands over by recipient, greetings and announcements apart, from midnight', function () {
    serviceMail();
    serviceMail('accounts@example.com');

    $campaign = MailCampaign::query()->create(['kind' => 'broadcast', 'subject' => 'New batches', 'message' => 'Hello.', 'audience' => ['groups' => ['students']]]);
    app(Campaigns::class)->testSend($campaign, $this->owner);

    expect(MailBudget::today())->toBe(['sent' => 4, 'promotional' => 1, 'refused' => 0])
        ->and(MailBudget::summary())->toMatchArray(['limit' => 100, 'reserve' => 40, 'left' => 96, 'promotionalLeft' => 56, 'state' => 'ok']);

    $this->travel(1)->days();

    expect(MailBudget::today()['sent'])->toBe(0)
        ->and(MailBudget::summary()['left'])->toBe(100);
});

it('leaves greetings and announcements only what the day has above the part kept for service mail', function () {
    config(['company.mail.daily_limit' => 10, 'company.mail.service_reserve' => 7]);

    expect(MailBudget::promotionalLeft())->toBe(3);

    serviceMail();
    serviceMail();
    expect(MailBudget::promotionalLeft())->toBe(1);

    // A busy day of service mail leaves greetings nothing, and still goes on itself.
    serviceMail();
    serviceMail();
    expect(MailBudget::promotionalLeft())->toBe(0)
        ->and(MailBudget::summary())->toMatchArray(['left' => 6, 'state' => 'ok']);

    foreach (range(1, 5) as $i) {
        serviceMail();
    }
    expect(MailBudget::summary())->toMatchArray(['sent' => 9, 'left' => 1, 'state' => 'low']);

    serviceMail();
    serviceMail();
    expect(MailBudget::summary())->toMatchArray(['sent' => 11, 'left' => 0, 'state' => 'used_up']);
});

it('counts a queued message the mail server refused once its retries are spent, and nothing else', function () {
    $job = Mockery::mock(Job::class);

    event(new JobFailed('database', $job, new TransportException('550 5.4.6 Daily sending quota exceeded')));
    event(new JobFailed('database', $job, new RuntimeException('Something else went wrong')));

    expect(MailBudget::today()['refused'])->toBe(1);
});

it('never fails a message that went because it could not be counted', function () {
    Exceptions::fake();
    Schema::drop('mail_usage');

    serviceMail();

    Exceptions::assertReported(QueryException::class);
});

it('lets the owner set the plan\'s limit and the part kept for service mail, audited, and nobody without the permission', function () {
    $this->actingAs($this->owner)->get('/admin/settings?tab=mail')->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Settings')->where('mail.limit', 100)->where('mail.reserve', 40));

    $this->actingAs($this->owner)->put('/admin/settings/mail', ['daily_limit' => 50, 'service_reserve' => 60])
        ->assertSessionHasErrors(['service_reserve' => 'Keep no more than the plan allows in a day.']);
    $this->actingAs($this->owner)->put('/admin/settings/mail', ['daily_limit' => 0, 'service_reserve' => 0])
        ->assertSessionHasErrors('daily_limit');

    $this->actingAs($this->owner)->put('/admin/settings/mail', ['daily_limit' => 1000, 'service_reserve' => 150])
        ->assertRedirect()->assertSessionHas('success');

    expect(MailBudget::limit())->toBe(1000)
        ->and(MailBudget::reserve())->toBe(150)
        ->and(MailBudget::promotionalLeft())->toBe(850)
        ->and(AuditLog::query()->where('action', 'settings.mail_updated')->sole()->changes)
        ->toBe(['daily_limit' => ['from' => 100, 'to' => 1000], 'service_reserve' => ['from' => 40, 'to' => 150]]);

    $viewer = User::factory()->admin()->create();
    $viewer->permissions()->attach(Permission::query()->where('key', 'settings.view')->get());

    $this->actingAs($viewer)->get('/admin/settings')->assertOk();
    $this->actingAs($viewer)->put('/admin/settings/mail', ['daily_limit' => 5000, 'service_reserve' => 0])->assertForbidden();
    expect(MailBudget::limit())->toBe(1000);
});

it('shows what is left today on the dashboard, and says so when it is used up or the server refused mail', function () {
    config(['company.mail.daily_limit' => 3, 'company.mail.service_reserve' => 1]);
    serviceMail();

    $this->actingAs($this->owner)->get('/admin')->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Dashboard')
            ->where('mail.left', 2)
            ->where('mail.limit', 3)
            ->where('mail.promotionalLeft', 1)
            ->where('attention', fn ($items) => collect($items)->doesntContain(fn ($item) => str_contains($item['title'], 'mail'))));

    serviceMail('accounts@example.com');
    MailBudget::recordRefused();

    $this->actingAs($this->owner)->get('/admin')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('mail.left', 0)
            ->where('mail.state', 'used_up')
            ->where('attention', fn ($items) => collect($items)->contains(fn ($item) => $item['title'] === 'Today\'s mail is used up: 3 of the 3 your plan allows' && $item['href'] === '/admin/settings?tab=mail')
                && collect($items)->contains(fn ($item) => $item['title'] === '1 message refused by the mail server today')));
});
