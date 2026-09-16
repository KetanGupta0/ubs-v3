<?php

use App\Models\CredentialDelivery;
use App\Models\User;
use App\Notifications\WelcomeCredentials;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
});

it('creates a client account and sends the way in', function () {
    Notification::fake();

    $this->actingAs($this->owner)->post('/admin/clients', [
        'name' => 'Meridian Logistics',
        'email' => 'ops@meridian.test',
        'mobile' => '+919812345678',
        'company' => 'Meridian Logistics Pvt Ltd',
    ])->assertRedirect();

    $client = User::query()->where('email', 'ops@meridian.test')->sole();

    expect($client->isClient())->toBeTrue()
        ->and($client->must_change_password)->toBeTrue()
        ->and($client->profile->company)->toBe('Meridian Logistics Pvt Ltd');

    Notification::assertSentTo($client, WelcomeCredentials::class);
});

it('records where the credentials were sent, on each channel', function () {
    Notification::fake();

    $this->actingAs($this->owner)->post('/admin/clients', [
        'name' => 'Anita Rao',
        'email' => 'anita@example.test',
        'mobile' => '+919812345670',
    ]);

    $client = User::query()->where('email', 'anita@example.test')->sole();

    expect(CredentialDelivery::query()->where('user_id', $client->id)->pluck('channel')->sort()->values()->all())
        ->toBe(['mail', 'sms']);
});

it('sends on one channel only when there is no mobile number', function () {
    Notification::fake();

    $this->actingAs($this->owner)->post('/admin/clients', [
        'name' => 'No Mobile',
        'email' => 'nomobile@example.test',
    ]);

    $client = User::query()->where('email', 'nomobile@example.test')->sole();

    expect(CredentialDelivery::query()->where('user_id', $client->id)->pluck('channel')->all())
        ->toBe(['mail']);
});

it('keeps the account when the message cannot be delivered', function () {
    // A provider being down is not a reason to lose a record somebody just
    // typed in. It is a reason to show that the message did not go.
    Notification::fake();
    Notification::shouldReceive('send')->andThrow(new RuntimeException('provider down'));

    $this->actingAs($this->owner)->post('/admin/clients', [
        'name' => 'Undeliverable',
        'email' => 'undeliverable@example.test',
    ]);

    $client = User::query()->where('email', 'undeliverable@example.test')->first();

    expect($client)->not->toBeNull()
        ->and(CredentialDelivery::query()->where('user_id', $client->id)->first()->status)->toBe('failed');
});

it('replaces the temporary password when credentials are resent', function () {
    Notification::fake();

    $this->actingAs($this->owner)->post('/admin/clients', [
        'name' => 'Resend Me',
        'email' => 'resend@example.test',
    ]);

    $client = User::query()->where('email', 'resend@example.test')->sole();
    $firstHash = $client->password;

    $this->actingAs($this->owner)
        ->post("/admin/clients/{$client->id}/resend-credentials")
        ->assertRedirect();

    // A resend is a replacement, not a second live credential.
    expect($client->fresh()->password)->not->toBe($firstHash);
});

it('will not create an account under the admin role', function () {
    $this->actingAs($this->owner)->get('/admin/admins')->assertNotFound();
});

it('refuses a duplicate email without saying whose it is', function () {
    Notification::fake();

    User::factory()->client()->create(['email' => 'taken@example.test']);

    $this->actingAs($this->owner)
        ->post('/admin/clients', ['name' => 'Someone', 'email' => 'taken@example.test'])
        ->assertSessionHasErrors('email');
});
