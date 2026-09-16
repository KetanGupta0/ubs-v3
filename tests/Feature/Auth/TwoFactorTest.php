<?php

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\LoginPipeline;
use App\Services\Auth\TwoFactorService;
use PragmaRX\Google2FA\Google2FA;

/** Turn on two factor for a user and return the shared secret. */
function enableTwoFactor(User $user): string
{
    $service = app(TwoFactorService::class);
    $record = $service->beginSetup($user);

    $code = app(Google2FA::class)->getCurrentOtp($record->secret);
    $service->confirmSetup($user->fresh(), $code);

    return $record->secret;
}

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'aarti@example.com',
        'password' => 'Password123!',
    ]);
});

it('stops at the challenge instead of signing in', function () {
    enableTwoFactor($this->user);

    $this->post('/login', [
        'identifier' => 'aarti@example.com',
        'password' => 'Password123!',
    ])->assertRedirect('/two-factor-challenge');

    // The password alone must not produce a session.
    $this->assertGuest();
});

it('completes sign in with a valid app code', function () {
    $secret = enableTwoFactor($this->user);

    $this->post('/login', ['identifier' => 'aarti@example.com', 'password' => 'Password123!']);

    $this->post('/two-factor-challenge', [
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])->assertRedirect('/student');

    $this->assertAuthenticatedAs($this->user);
});

it('refuses a wrong app code', function () {
    enableTwoFactor($this->user);

    $this->post('/login', ['identifier' => 'aarti@example.com', 'password' => 'Password123!']);

    $this->post('/two-factor-challenge', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('accepts a recovery code once and only once', function () {
    $service = app(TwoFactorService::class);
    $record = $service->beginSetup($this->user);
    $service->confirmSetup($this->user->fresh(), app(Google2FA::class)->getCurrentOtp($record->secret));

    $codes = $this->user->fresh()->twoFactorSecret->regenerateRecoveryCodes();
    $code = $codes[0];

    $this->post('/login', ['identifier' => 'aarti@example.com', 'password' => 'Password123!']);
    $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/student');
    $this->assertAuthenticatedAs($this->user);

    $this->post('/logout');

    // The same code a second time must fail, or a stolen printout stays useful.
    $this->post('/login', ['identifier' => 'aarti@example.com', 'password' => 'Password123!']);
    $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('challenges a one time code sign in too', function () {
    enableTwoFactor($this->user);

    $otp = OtpCode::query()->create([
        'user_id' => $this->user->id,
        'destination' => 'aarti@example.com',
        'channel' => 'email',
        'purpose' => OtpPurpose::Login->value,
        'code_hash' => bcrypt('123456'),
        'expires_at' => now()->addMinutes(10),
    ]);

    // A code sent to a registered address proves possession, which is one
    // factor, so the authenticator app is still required on top.
    $this->post('/login/code/verify', ['identifier' => 'aarti@example.com', 'code' => '123456'])
        ->assertRedirect('/two-factor-challenge');

    $this->assertGuest();
    expect($otp->fresh()->consumed_at)->not->toBeNull();
});

it('sends someone with no pending challenge back to sign in', function () {
    $this->get('/two-factor-challenge')->assertRedirect('/login');
    $this->post('/two-factor-challenge', ['code' => '123456'])->assertRedirect('/login');
});

it('abandons the challenge on request', function () {
    enableTwoFactor($this->user);
    $this->post('/login', ['identifier' => 'aarti@example.com', 'password' => 'Password123!']);

    $this->delete('/two-factor-challenge')->assertRedirect('/login');
    $this->get('/two-factor-challenge')->assertRedirect('/login');

    expect(session()->has(LoginPipeline::PENDING_KEY))->toBeFalse();
});

it('sets up two factor from the security screen', function () {
    $this->actingAs($this->user)
        ->post('/settings/two-factor')
        ->assertRedirect();

    $record = $this->user->fresh()->twoFactorSecret;

    // Present but not yet enforced, so an abandoned setup cannot lock anyone out.
    expect($record)->not->toBeNull()
        ->and($record->isConfirmed())->toBeFalse()
        ->and($this->user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('enforces two factor only after the app code is confirmed', function () {
    $this->actingAs($this->user)->post('/settings/two-factor');
    $secret = $this->user->fresh()->twoFactorSecret->secret;

    $this->actingAs($this->user)->post('/settings/two-factor/confirm', [
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ])->assertSessionHas('recoveryCodes');

    expect($this->user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('will not turn two factor off without the password', function () {
    enableTwoFactor($this->user);

    $this->actingAs($this->user)
        ->delete('/settings/two-factor', ['password' => 'the-wrong-password'])
        ->assertSessionHasErrors('password');

    expect($this->user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('turns two factor off with the correct password', function () {
    enableTwoFactor($this->user);

    $this->actingAs($this->user)
        ->delete('/settings/two-factor', ['password' => 'Password123!'])
        ->assertSessionHasNoErrors();

    expect($this->user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});
