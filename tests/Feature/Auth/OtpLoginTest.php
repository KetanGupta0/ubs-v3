<?php

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\OneTimeCodeNotification;
use App\Services\Auth\OtpService;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->user = User::factory()->create([
        'email' => 'aarti@example.com',
        'mobile' => '+919876543210',
    ]);
});

/** The plain code is never stored, so tests set a known one directly. */
function issueKnownCode(User $user, string $destination, string $code = '123456'): OtpCode
{
    return OtpCode::query()->create([
        'user_id' => $user->id,
        'destination' => $destination,
        'channel' => str_contains($destination, '@') ? 'email' : 'sms',
        'purpose' => OtpPurpose::Login->value,
        'code_hash' => Hash::make($code),
        'expires_at' => now()->addMinutes(10),
    ]);
}

it('sends a code to a registered email address', function () {
    $this->post('/login/code', ['identifier' => 'aarti@example.com'])
        ->assertRedirect();

    expect(OtpCode::query()->where('destination', 'aarti@example.com')->count())->toBe(1);

    Notification::assertSentTo($this->user, OneTimeCodeNotification::class);
});

it('sends a code to a registered mobile number', function () {
    $sent = [];

    $this->app->instance(SmsSender::class, new class($sent) implements SmsSender
    {
        public function __construct(public array &$sent) {}

        public function send(string $to, string $message, array $context = []): void
        {
            $this->sent[] = compact('to', 'message');
        }
    });

    $this->post('/login/code', ['identifier' => '98765 43210'])->assertRedirect();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['to'])->toBe('+919876543210');
});

it('reports the same thing for an address with no account', function () {
    $response = $this->post('/login/code', ['identifier' => 'nobody@example.com']);

    // No code exists, but the browser is told exactly what it would be told for
    // a real account, so this cannot be used to test which emails are known.
    $response->assertRedirect()->assertSessionHas('otpSentTo');

    expect(OtpCode::query()->where('destination', 'nobody@example.com')->exists())->toBeFalse();
});

it('signs in with a correct code', function () {
    issueKnownCode($this->user, 'aarti@example.com');

    $this->post('/login/code/verify', [
        'identifier' => 'aarti@example.com',
        'code' => '123456',
    ])->assertRedirect('/student');

    $this->assertAuthenticatedAs($this->user);
});

it('refuses a wrong code and counts the attempt', function () {
    $otp = issueKnownCode($this->user, 'aarti@example.com');

    $this->post('/login/code/verify', [
        'identifier' => 'aarti@example.com',
        'code' => '000000',
    ])->assertSessionHasErrors('code');

    $this->assertGuest();
    expect($otp->fresh()->attempts)->toBe(1);
});

it('burns the code after five wrong guesses', function () {
    $otp = issueKnownCode($this->user, 'aarti@example.com');

    foreach (range(1, 5) as $ignored) {
        $this->post('/login/code/verify', ['identifier' => 'aarti@example.com', 'code' => '000000']);
    }

    // Even the right code is dead now, so guessing cannot be resumed.
    $this->post('/login/code/verify', ['identifier' => 'aarti@example.com', 'code' => '123456'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
    expect($otp->fresh()->consumed_at)->not->toBeNull();
});

it('refuses an expired code', function () {
    issueKnownCode($this->user, 'aarti@example.com')
        ->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->post('/login/code/verify', ['identifier' => 'aarti@example.com', 'code' => '123456'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('refuses a code that has already been used', function () {
    issueKnownCode($this->user, 'aarti@example.com');

    $this->post('/login/code/verify', ['identifier' => 'aarti@example.com', 'code' => '123456']);
    $this->post('/logout');

    $this->post('/login/code/verify', ['identifier' => 'aarti@example.com', 'code' => '123456'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('retires an earlier code when a new one is issued', function () {
    $first = issueKnownCode($this->user, 'aarti@example.com');

    app(OtpService::class)->issue($this->user, 'aarti@example.com', OtpChannel::Email, OtpPurpose::Login);

    // Otherwise asking for a second code would widen the guessing surface
    // instead of replacing it.
    expect($first->fresh()->consumed_at)->not->toBeNull();
});

it('refuses to send a second code inside the cooldown', function () {
    $this->post('/login/code', ['identifier' => 'aarti@example.com']);

    $this->post('/login/code', ['identifier' => 'aarti@example.com'])
        ->assertSessionHasErrors('identifier');

    expect(OtpCode::query()->where('destination', 'aarti@example.com')->count())->toBe(1);
});

it('marks the email verified when signing in by emailed code', function () {
    $this->user->forceFill(['email_verified_at' => null])->save();
    issueKnownCode($this->user, 'aarti@example.com');

    $this->post('/login/code/verify', ['identifier' => 'aarti@example.com', 'code' => '123456']);

    expect($this->user->fresh()->email_verified_at)->not->toBeNull();
});

it('will not sign in a suspended account by code', function () {
    $this->user->forceFill(['status' => 'suspended'])->save();
    issueKnownCode($this->user, 'aarti@example.com');

    $this->post('/login/code/verify', ['identifier' => 'aarti@example.com', 'code' => '123456'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();
});
