<?php

use App\Enums\Role;
use App\Mail\CampaignMail;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lead;
use App\Models\LegalDocument;
use App\Models\LegalDocumentVersion;
use App\Models\MailCampaign;
use App\Models\MailCampaignRecipient;
use App\Models\MailSuppression;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\CampaignPosted;
use App\Services\Mail\Audience;
use App\Services\Mail\Suppressions;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/** Publish the drafted privacy policy, as the owner would, so enquiry senders may be mailed. */
function publishPrivacyDraft(User $owner): void
{
    LegalDocument::query()->where('key', 'privacy')->sole()->draft()->sole()->publish($owner);
}

function mailStaff(array $keys): User
{
    $user = User::factory()->admin()->create();
    $user->permissions()->attach(Permission::query()->whereIn('key', $keys)->get());

    return $user;
}

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    $this->seed(PermissionSeeder::class);
    Setting::put('company.legal_name', 'Unboundbyte Solutions Private Limited', 'company');

    $this->owner = User::factory()->owner()->create();
    $this->writer = mailStaff(['mail.broadcast']);

    $this->meera = User::factory()->create(['role' => Role::Student, 'name' => 'Meera Iyer', 'email' => 'meera@example.com']);
    $this->ravi = User::factory()->create(['role' => Role::Student, 'name' => 'Ravi Kumar', 'email' => 'ravi@example.com', 'last_login_at' => now()]);
    $this->client = User::factory()->client()->create(['name' => 'Priya Acme', 'email' => 'priya@example.com']);
    $this->lead = Lead::query()->create(['name' => 'Anil Shah', 'email' => 'Anil@Example.com', 'message' => 'A question about the course', 'interest' => 'training']);
    // An enquiry from somebody who has an account counts as the account.
    Lead::query()->create(['name' => 'Meera', 'email' => 'meera@example.com', 'message' => 'Another question', 'interest' => 'general']);

    $this->send = fn (array $audience, string $action = 'send', array $extra = []) => $this->actingAs($this->writer)->post('/admin/mail/broadcasts', [
        'subject' => 'New batches, {first_name}', 'message' => "Hello {name},\n\nNew **batches** start next month.", 'audience' => $audience, 'action' => $action, ...$extra,
    ]);
});

it('drafts the privacy policy that greetings need, and leaves enquiry senders out until it is published and switched on', function () {
    $privacy = LegalDocument::query()->where('key', 'privacy')->sole();
    $draft = $privacy->draft()->sole();

    expect($draft->version)->toBe(2)
        ->and($draft->body)->toContain('## Greetings and announcements by email')
        ->and($draft->body)->not->toContain('do not add you to a mailing list')
        ->and($draft->body)->toContain('If you buy from our shop')
        ->and($privacy->versions()->where('status', LegalDocumentVersion::PUBLISHED)->sole()->body)->toContain('do not add you to a mailing list');

    $groups = ['groups' => ['students', 'enquiries']];
    expect(app(Audience::class)->recipients($groups)->pluck('email')->all())->toBe(['meera@example.com', 'ravi@example.com']);

    // Not while the published policy still promises otherwise.
    $this->actingAs($this->writer)->post('/admin/mail/enquiries', ['on' => true, 'confirm' => true])->assertSessionHas('error');
    publishPrivacyDraft($this->owner);
    $this->actingAs($this->writer)->post('/admin/mail/enquiries', ['on' => true])->assertSessionHasErrors('confirm');
    $this->actingAs($this->writer)->post('/admin/mail/enquiries', ['on' => true, 'confirm' => true])->assertSessionHas('success');

    expect(app(Audience::class)->recipients($groups)->pluck('email')->all())->toBe(['meera@example.com', 'ravi@example.com', 'anil@example.com']);
});

it('counts the audience live, staff never in it, filters narrowing the right people', function () {
    $course = Course::query()->create([
        'title' => 'Laravel', 'slug' => 'laravel', 'type' => 'course', 'tagline' => 'Web apps in PHP', 'summary' => 'A course.',
        'level' => 'beginner', 'duration_weeks' => 8, 'price' => 0, 'visibility' => 'public', 'is_published' => true,
    ]);
    Enrollment::query()->create(['user_id' => $this->meera->id, 'course_id' => $course->id, 'status' => 'active', 'enrolled_at' => now()]);
    MailSuppression::query()->create(['email' => 'priya@example.com', 'reason' => 'unsubscribed']);

    $count = fn (array $audience) => $this->actingAs($this->writer)->postJson('/admin/mail/audience/count', ['audience' => $audience])->json();

    expect($count(['groups' => ['students', 'clients']]))->toBe(['total' => 3, 'suppressed' => 1, 'reachable' => 2])
        // The course narrows the students, not the clients.
        ->and($count(['groups' => ['students', 'clients'], 'course_id' => $course->id])['total'])->toBe(2)
        ->and($count(['groups' => ['students'], 'never_signed_in' => true])['total'])->toBe(1);

    $this->actingAs(mailStaff(['mail.occasions']))->postJson('/admin/mail/audience/count', ['audience' => ['groups' => ['students']]])->assertForbidden();
});

it('puts an announcement in the platform at once and mails it within the day\'s budget, people without an account first', function () {
    publishPrivacyDraft($this->owner);
    Setting::put(Audience::SETTING, true, 'mail');
    // Room for two greetings or announcements a day, past the part kept for service mail.
    config(['company.mail.daily_limit' => 12, 'company.mail.service_reserve' => 10]);
    MailSuppression::query()->create(['email' => 'ravi@example.com', 'reason' => 'unsubscribed']);

    ($this->send)(['groups' => ['students', 'clients', 'enquiries']])->assertRedirect();
    $campaign = MailCampaign::query()->sole();

    expect($campaign->status)->toBe('sending')
        ->and($campaign->counts())->toMatchArray(['recipients' => 4, 'queued' => 3, 'skipped' => 1]);
    // In everybody's notifications straight away, except whoever stopped them.
    Notification::assertSentTo([$this->meera, $this->client], CampaignPosted::class);
    Notification::assertNotSentTo($this->ravi, CampaignPosted::class);
    Mail::assertNothingSent();

    $this->artisan('mail:send-promotional')->assertSuccessful();

    // Two today, and the enquiry sender, who has no other way to hear, among them.
    Mail::assertSent(CampaignMail::class, 2);
    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->hasTo('anil@example.com')
        && $mail->envelope()->subject === 'New batches, Anil'
        && str_contains($mail->headers()->text['List-Unsubscribe'], '/unsubscribe/')
        && $mail->headers()->text['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click'
        && str_contains($mail->render(), 'You sent us an enquiry on'));

    $this->artisan('mail:send-promotional');
    Mail::assertSent(CampaignMail::class, 2);

    $this->travel(1)->days();
    $this->artisan('mail:send-promotional');
    Mail::assertSent(CampaignMail::class, 3);
    expect($campaign->fresh()->status)->toBe('sent');

    // Read in the platform by somebody it went to, and nobody else.
    $this->actingAs($this->meera)->get("/messages/{$campaign->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('Message')->where('message.subject', 'New batches, Meera')->where('message.html', fn ($html) => str_contains($html, '<strong>batches</strong>')));
    $this->actingAs($this->ravi)->get("/messages/{$campaign->id}")->assertNotFound();
});

it('keeps to a couple a week for any one address', function () {
    ($this->send)(['groups' => ['students']]);
    ($this->send)(['groups' => ['students']]);
    ($this->send)(['groups' => ['students']]);

    $this->artisan('mail:send-promotional');

    expect(MailCampaignRecipient::query()->where('email', 'meera@example.com')->where('status', 'sent')->count())->toBe(2)
        ->and(MailCampaignRecipient::query()->where('email', 'meera@example.com')->where('skip_reason', 'weekly_cap')->count())->toBe(1);
});

it('stops them from the link in one click, honoured at once, and only for that address', function () {
    ($this->send)(['groups' => ['students']]);
    $campaign = MailCampaign::query()->sole();
    $link = Suppressions::unsubscribeUrl('meera@example.com', $campaign->id);

    $this->get($link)->assertOk()->assertInertia(fn ($page) => $page->component('marketing/Unsubscribe')->where('stopped', false)->where('email', 'me•••@example.com'));
    expect(MailSuppression::query()->count())->toBe(0); // opening the link does nothing by itself

    $this->get(str_replace('c='.$campaign->id, 'c=999', $link))->assertForbidden();

    // A mail app's own button: no session, no token, just the signed link.
    $this->post($link, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('Unsubscribed');
    // The signature is over the path, so the link works whatever the site's address is by then.
    expect(parse_url($link, PHP_URL_PATH))->toStartWith('/unsubscribe/');
    expect(MailSuppression::query()->sole())->toMatchArray(['email' => 'meera@example.com', 'reason' => 'unsubscribed', 'campaign_id' => $campaign->id]);

    $this->artisan('mail:send-promotional');
    Mail::assertNotSent(CampaignMail::class, fn ($mail) => $mail->hasTo('meera@example.com'));
    expect($campaign->counts()['unsubscribed'])->toBe(1);

    // Changing one's mind works; a bounced address is ours to take off.
    $resume = Suppressions::resubscribeUrl(Suppressions::encode('meera@example.com'));
    $this->post($resume)->assertSessionHas('success');
    expect(MailSuppression::query()->count())->toBe(0);

    app(Suppressions::class)->suppress('meera@example.com', 'bounced', by: $this->owner);
    $this->post($resume)->assertSessionHas('error');
});

it('gives account holders their own switch, by mail and in the platform', function () {
    $this->actingAs($this->meera)->get('/settings/security')->assertInertia(fn ($page) => $page->where('promotionalMail.receiving', true));
    $this->actingAs($this->meera)->put('/settings/mail', ['receive' => false])->assertSessionHas('success');
    expect(MailSuppression::has('meera@example.com'))->toBeTrue();

    ($this->send)(['groups' => ['students']]);
    Notification::assertNotSentTo($this->meera, CampaignPosted::class);

    $this->actingAs($this->meera)->put('/settings/mail', ['receive' => true]);
    expect(MailSuppression::has('meera@example.com'))->toBeFalse();
    $this->actingAs($this->writer)->get('/settings/security')->assertInertia(fn ($page) => $page->where('promotionalMail', null));
});

it('sends a scheduled announcement at its time, and a cancelled one not at all', function () {
    ($this->send)(['groups' => ['students']], 'schedule', ['scheduled_at' => now()->addHour()->format('Y-m-d H:i')])->assertSessionHas('success');
    ($this->send)(['groups' => ['students']], 'draft');
    [$scheduled, $draft] = MailCampaign::query()->orderBy('id')->get()->all();

    $this->artisan('mail:send-promotional');
    expect($scheduled->fresh()->status)->toBe('scheduled');

    $this->travel(61)->minutes();
    $this->artisan('mail:send-promotional');
    expect($scheduled->fresh()->status)->toBe('sent')
        ->and($draft->fresh()->status)->toBe('draft');

    $this->actingAs($this->writer)->post("/admin/mail/broadcasts/{$draft->id}/cancel")->assertSessionHas('success');
    expect($draft->fresh()->status)->toBe('cancelled');
    $this->actingAs($this->writer)->post("/admin/mail/broadcasts/{$scheduled->id}/cancel")->assertSessionHas('error');
});

it('previews and tests a mail on the person writing it, never on the audience', function () {
    $html = $this->actingAs($this->writer)->post('/admin/mail/preview', ['subject' => 'Hello {first_name}', 'message' => 'A *new* course.'])->assertOk()->getContent();
    expect($html)->toContain('>new</em>')->toContain('Stop them');

    $this->actingAs($this->writer)->post('/admin/mail/test', ['subject' => 'Hello {first_name}', 'message' => 'A new course.'])->assertSessionHas('success');
    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->hasTo($this->writer->email) && str_starts_with($mail->envelope()->subject, '[Test] Hello'));
    expect(MailCampaign::query()->count())->toBe(0);
});
