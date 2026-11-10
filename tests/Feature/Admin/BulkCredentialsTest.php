<?php

use App\Jobs\SendCredentials;
use App\Models\AuditLog;
use App\Models\CredentialDelivery;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\WelcomeCredentials;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
});

/** An account brought across from the old platform: never signed in, password unknown. */
function legacyStudent(array $attributes = []): User
{
    return User::factory()->student()->create([
        'must_change_password' => true,
        'last_login_at' => null,
        ...$attributes,
    ]);
}

function sendInBulk($test, User $actor, array $payload, string $role = 'students', string $query = '')
{
    return $test->actingAs($actor)
        ->from("/admin/{$role}")
        ->post("/admin/{$role}/send-credentials{$query}", $payload);
}

it('sends fresh credentials to the selected accounts', function () {
    Notification::fake();

    $first = legacyStudent();
    $second = legacyStudent();
    $hashes = [$first->password, $second->password];

    sendInBulk($this, $this->owner, ['scope' => 'selected', 'ids' => [$first->id, $second->id]])
        ->assertRedirect('/admin/students')
        ->assertSessionHas('success', 'Credentials are on their way to 2 accounts.');

    Notification::assertSentTo([$first, $second], WelcomeCredentials::class);

    expect([$first->fresh()->password, $second->fresh()->password])->not->toContain($hashes[0])
        ->and($first->fresh()->must_change_password)->toBeTrue();

    // The queued job has no signed in user, so the record says who pressed the button.
    expect(CredentialDelivery::query()->where('user_id', $first->id)->value('created_by'))->toBe($this->owner->id)
        ->and(AuditLog::query()->where('action', 'account.credentials_resent')->where('subject_id', $first->id)->value('actor_id'))
        ->toBe($this->owner->id)
        ->and(AuditLog::query()->where('action', 'account.credentials_bulk_queued')->sole()->changes['queued'])
        ->toBe(2);
});

it('never replaces a password somebody chose and is using', function () {
    Notification::fake();

    $inUse = User::factory()->student()->create([
        'must_change_password' => false,
        'last_login_at' => now()->subDay(),
    ]);
    $hash = $inUse->password;
    $legacy = legacyStudent();

    sendInBulk($this, $this->owner, ['scope' => 'selected', 'ids' => [$inUse->id, $legacy->id]])
        ->assertSessionHas('success', 'Credentials are on their way to 1 account. Skipped 1 who already have a password they chose.');

    expect($inUse->fresh()->password)->toBe($hash);
    Notification::assertNotSentTo($inUse, WelcomeCredentials::class);
    Notification::assertSentTo($legacy, WelcomeCredentials::class);
});

it('leaves alone an account brought across with its own password, even before its first sign in here', function () {
    Notification::fake();

    // Generation 2: the hash came across from the old site, which never
    // recorded sign ins, so "never signed in" says nothing about whose
    // password it is.
    $carriedOver = User::factory()->student()->create([
        'must_change_password' => false,
        'last_login_at' => null,
    ]);
    $hash = $carriedOver->password;

    sendInBulk($this, $this->owner, ['scope' => 'selected', 'ids' => [$carriedOver->id]])
        ->assertSessionHas('warning', 'Nothing was sent. Skipped 1 who already have a password they chose.');

    expect($carriedOver->fresh()->password)->toBe($hash);
    Notification::assertNothingSent();
});

it('sends to a Google only account, which has no password to lose', function () {
    Notification::fake();

    $googleOnly = User::factory()->student()->passwordless()->create(['must_change_password' => false]);

    sendInBulk($this, $this->owner, ['scope' => 'selected', 'ids' => [$googleOnly->id]])
        ->assertSessionHas('success', 'Credentials are on their way to 1 account.');

    Notification::assertSentTo($googleOnly, WelcomeCredentials::class);
});

it('still sends to somebody who signed in but is on a temporary password', function () {
    Notification::fake();

    $stuck = legacyStudent(['last_login_at' => now()->subDay()]);

    sendInBulk($this, $this->owner, ['scope' => 'selected', 'ids' => [$stuck->id]])
        ->assertSessionHas('success', 'Credentials are on their way to 1 account.');

    Notification::assertSentTo($stuck, WelcomeCredentials::class);
});

it('skips suspended accounts and anyone sent credentials a moment ago', function () {
    Notification::fake();

    $suspended = legacyStudent(['status' => 'suspended']);
    $recent = legacyStudent();
    CredentialDelivery::query()->create([
        'user_id' => $recent->id,
        'channel' => 'mail',
        'destination' => $recent->email,
        'status' => 'sent',
    ]);

    sendInBulk($this, $this->owner, ['scope' => 'selected', 'ids' => [$suspended->id, $recent->id]])
        ->assertSessionHas('warning', 'Nothing was sent. Skipped 1 who already have credentials on the way or were just sent some. Skipped 1 who are suspended.');

    Notification::assertNothingSent();
});

it('sends to everybody the filtered list shows, across every page', function () {
    Notification::fake();

    $neverSent = collect(range(1, 3))->map(fn () => legacyStudent());
    $alreadySent = legacyStudent();
    $alreadySent->credentialDeliveries()->create([
        'channel' => 'mail',
        'destination' => $alreadySent->email,
        'status' => 'sent',
        'created_at' => now()->subDays(3),
    ]);

    sendInBulk($this, $this->owner, ['scope' => 'matching'], query: '?filter[credentials]=never&per_page=10')
        ->assertSessionHas('success', 'Credentials are on their way to 3 accounts.');

    Notification::assertSentTo($neverSent->all(), WelcomeCredentials::class);
    Notification::assertNotSentTo($alreadySent, WelcomeCredentials::class);
});

it('only reaches accounts of the list it was sent from', function () {
    Notification::fake();

    $client = User::factory()->client()->create(['must_change_password' => true, 'last_login_at' => null]);

    sendInBulk($this, $this->owner, ['scope' => 'selected', 'ids' => [$client->id]])
        ->assertSessionHas('error', 'No accounts matched, so nothing was sent.');

    Notification::assertNothingSent();
});

it('needs the update permission for that list', function () {
    $student = legacyStudent();

    $viewer = User::factory()->admin()->create();
    $viewer->permissions()->attach(Permission::query()->where('key', 'students.view')->sole());

    sendInBulk($this, $viewer->fresh(), ['scope' => 'selected', 'ids' => [$student->id]])->assertForbidden();

    // Being allowed to update clients is not being allowed to reset students.
    $clientEditor = User::factory()->admin()->create();
    $clientEditor->permissions()->attach(Permission::query()->whereIn('key', ['students.view', 'clients.update'])->get());

    sendInBulk($this, $clientEditor->fresh(), ['scope' => 'selected', 'ids' => [$student->id]])->assertForbidden();
});

it('spreads the sends out so a provider limit is not hit', function () {
    Queue::fake();
    config(['company.credentials.bulk_per_minute' => 2]);

    $students = collect(range(1, 5))->map(fn () => legacyStudent());

    sendInBulk($this, $this->owner, ['scope' => 'selected', 'ids' => $students->pluck('id')->all()]);

    $delays = collect(Queue::pushed(SendCredentials::class))
        ->map(fn (SendCredentials $job) => (int) round(now()->diffInMinutes($job->delay, true)))
        ->sort()
        ->values()
        ->all();

    expect($delays)->toBe([0, 0, 1, 1, 2]);
});

it('looks again before sending, in case the person signed in while it waited', function () {
    Queue::fake();
    Notification::fake();

    $student = legacyStudent();

    sendInBulk($this, $this->owner, ['scope' => 'selected', 'ids' => [$student->id]]);

    // They found the old way in and chose a password before the job ran.
    $student->forceFill(['must_change_password' => false, 'last_login_at' => now()])->save();
    $hash = $student->fresh()->password;

    $job = Queue::pushed(SendCredentials::class)->sole();
    app()->call([$job, 'handle']);

    expect($student->fresh()->password)->toBe($hash);
    Notification::assertNothingSent();

    // The send it had planned is closed, not left waiting for ever.
    expect($student->credentialDeliveries()->pluck('status')->unique()->all())->toBe(['skipped']);
});

it('shows a send as queued straight away, and a second click does not queue it again', function () {
    Queue::fake();

    $student = legacyStudent();

    sendInBulk($this, $this->owner, ['scope' => 'selected', 'ids' => [$student->id]])
        ->assertSessionHas('success', 'Credentials are on their way to 1 account.');

    $this->actingAs($this->owner)
        ->get('/admin/students?filter[credentials]=queued')
        ->assertInertia(fn ($page) => $page
            ->has('table.rows', 1)
            ->where('table.rows.0.credentials', 'Queued'));

    // Long after the cooldown, but the first send has still not gone out.
    $this->travel(2)->hours();

    sendInBulk($this, $this->owner, ['scope' => 'selected', 'ids' => [$student->id]])
        ->assertSessionHas('warning', 'Nothing was sent. Skipped 1 who already have credentials on the way or were just sent some.');

    Queue::assertPushed(SendCredentials::class, 1);
});

it('completes the rows it planned rather than recording the send twice', function () {
    Notification::fake();

    $student = legacyStudent(['mobile' => '+919811111111']);

    sendInBulk($this, $this->owner, ['scope' => 'selected', 'ids' => [$student->id]]);

    expect($student->credentialDeliveries()->orderBy('channel')->pluck('status', 'channel')->all())
        ->toBe(['mail' => 'sent', 'sms' => 'sent']);
});

it('shows when credentials were last sent, and filters on it', function () {
    $failed = legacyStudent(['name' => 'Failed Fiona']);
    $failed->credentialDeliveries()->create([
        'channel' => 'mail',
        'destination' => $failed->email,
        'status' => 'failed',
    ]);
    legacyStudent(['name' => 'Never Neha']);

    $this->actingAs($this->owner)
        ->get('/admin/students?filter[credentials]=failed')
        ->assertInertia(fn ($page) => $page
            ->where('canSendCredentials', true)
            ->has('table.rows', 1)
            ->where('table.rows.0.name', 'Failed Fiona')
            ->where('table.rows.0.credentialsFailed', true));

    $this->actingAs($this->owner)
        ->get('/admin/students?filter[credentials]=never')
        ->assertInertia(fn ($page) => $page
            ->has('table.rows', 1)
            ->where('table.rows.0.credentials', 'Never'));
});
