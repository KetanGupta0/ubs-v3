<?php

use App\Enums\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-secret',
    ]);
});

/** Stand in for what Google would hand back. */
function fakeGoogleUser(string $email, string $id = 'google-123', bool $verified = true): SocialiteUser
{
    $user = new SocialiteUser;
    $user->map([
        'id' => $id,
        'name' => 'Aarti Sharma',
        'email' => $email,
        'avatar' => 'https://example.com/avatar.png',
    ]);
    $user->user = ['email_verified' => $verified];

    return $user;
}

function mockGoogle(SocialiteUser $googleUser): void
{
    Socialite::shouldReceive('driver->user')->andReturn($googleUser);
}

it('creates a student account for a new Google user', function () {
    mockGoogle(fakeGoogleUser('new@example.com'));

    $this->get('/auth/google/callback')->assertRedirect('/student');

    $user = User::query()->where('email', 'new@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(Role::Student)
        // Google vouched for the address, so there is nothing left to confirm.
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->hasPassword())->toBeFalse();

    $this->assertAuthenticatedAs($user);
});

it('links Google to an account that already uses that address', function () {
    $existing = User::factory()->client()->create(['email' => 'client@example.com']);

    mockGoogle(fakeGoogleUser('client@example.com'));

    $this->get('/auth/google/callback')->assertRedirect('/client');

    expect(SocialAccount::query()->where('user_id', $existing->id)->where('provider', 'google')->exists())
        ->toBeTrue()
        // Linking must never change what the account is allowed to do.
        ->and($existing->fresh()->role)->toBe(Role::Client);

    expect(User::query()->count())->toBe(1);
});

it('refuses a Google account whose email is not verified', function () {
    $existing = User::factory()->create(['email' => 'victim@example.com']);

    mockGoogle(fakeGoogleUser('victim@example.com', 'google-999', verified: false));

    // Accepting an unverified address would hand over an existing account to
    // whoever could claim that address at Google.
    $this->get('/auth/google/callback')->assertRedirect('/login');

    $this->assertGuest();
    expect(SocialAccount::query()->count())->toBe(0);
});

it('signs the same Google user back in without creating a second account', function () {
    mockGoogle(fakeGoogleUser('repeat@example.com'));
    $this->get('/auth/google/callback');
    $this->post('/logout');

    mockGoogle(fakeGoogleUser('repeat@example.com'));
    $this->get('/auth/google/callback')->assertRedirect('/student');

    expect(User::query()->where('email', 'repeat@example.com')->count())->toBe(1)
        ->and(SocialAccount::query()->count())->toBe(1);
});

it('refuses a suspended account', function () {
    User::factory()->suspended()->create(['email' => 'suspended@example.com']);

    mockGoogle(fakeGoogleUser('suspended@example.com'));

    $this->get('/auth/google/callback')->assertRedirect('/login');
    $this->assertGuest();
});

it('still asks for two factor after Google', function () {
    $user = User::factory()->create(['email' => 'secured@example.com']);
    $service = app(TwoFactorService::class);
    $record = $service->beginSetup($user);
    $service->confirmSetup($user->fresh(), app(Google2FA::class)->getCurrentOtp($record->secret));

    mockGoogle(fakeGoogleUser('secured@example.com'));

    $this->get('/auth/google/callback')->assertRedirect('/two-factor-challenge');
    $this->assertGuest();
});

it('hides the Google routes when no client id is configured', function () {
    config(['services.google.client_id' => null]);

    $this->get('/auth/google/redirect')->assertNotFound();
});
