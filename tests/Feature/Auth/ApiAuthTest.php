<?php

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'aarti@example.com',
        'mobile' => '+919876543210',
        'password' => 'Password123!',
    ]);

    $this->device = [
        'device_name' => 'Pixel 9',
        'device_identifier' => 'device-abc',
        'platform' => 'android',
        'app_version' => '1.0.0',
    ];
});

it('issues a token for valid credentials', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'aarti@example.com',
        'password' => 'Password123!',
        ...$this->device,
    ])->assertOk()->assertJsonStructure(['token', 'data' => ['id', 'role']]);

    expect($this->user->tokens()->count())->toBe(1);

    $token = $this->user->tokens()->first();
    expect($token->platform)->toBe('android')
        ->and($token->device_identifier)->toBe('device-abc');

    // The returned token actually works.
    $this->withToken($response->json('token'))
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'aarti@example.com');
});

it('refuses wrong credentials with the same message as an unknown account', function () {
    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'aarti@example.com',
        'password' => 'wrong',
        ...$this->device,
    ])->assertStatus(422)->assertJsonPath('errors.identifier.0', 'Those details do not match any account.');

    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'nobody@example.com',
        'password' => 'wrong',
        ...$this->device,
    ])->assertStatus(422)->assertJsonPath('errors.identifier.0', 'Those details do not match any account.');
});

it('refuses a suspended account', function () {
    $this->user->forceFill(['status' => 'suspended'])->save();

    $this->postJson('/api/v1/auth/login', [
        'identifier' => 'aarti@example.com',
        'password' => 'Password123!',
        ...$this->device,
    ])->assertStatus(403);

    expect($this->user->tokens()->count())->toBe(0);
});

it('replaces the token when the same device signs in again', function () {
    foreach (range(1, 2) as $ignored) {
        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'aarti@example.com',
            'password' => 'Password123!',
            ...$this->device,
        ])->assertOk();
    }

    // Otherwise every sign in would leave another live credential behind.
    expect($this->user->tokens()->count())->toBe(1);
});

it('returns a challenge token instead of access when two factor is on', function () {
    $service = app(TwoFactorService::class);
    $record = $service->beginSetup($this->user);
    $service->confirmSetup($this->user->fresh(), app(Google2FA::class)->getCurrentOtp($record->secret));

    $response = $this->postJson('/api/v1/auth/login', [
        'identifier' => 'aarti@example.com',
        'password' => 'Password123!',
        ...$this->device,
    ])->assertStatus(202)->assertJsonPath('two_factor_required', true);

    $challenge = $response->json('challenge_token');

    // The challenge token must not open anything else.
    $this->withToken($challenge)->getJson('/api/v1/me')->assertStatus(403);

    $this->withToken($challenge)->postJson('/api/v1/auth/two-factor', [
        'code' => app(Google2FA::class)->getCurrentOtp($record->secret),
        ...$this->device,
    ])->assertOk()->assertJsonStructure(['token']);

    // It is spent once the challenge is answered.
    expect(PersonalAccessToken::query()->where('name', 'like', '%challenge%')->count())->toBe(0);
});

it('signs in with a one time code', function () {
    OtpCode::query()->create([
        'user_id' => $this->user->id,
        'destination' => 'aarti@example.com',
        'channel' => 'email',
        'purpose' => OtpPurpose::Login->value,
        'code_hash' => bcrypt('123456'),
        'expires_at' => now()->addMinutes(10),
    ]);

    $this->postJson('/api/v1/auth/code/verify', [
        'identifier' => 'aarti@example.com',
        'code' => '123456',
        ...$this->device,
    ])->assertOk()->assertJsonStructure(['token']);
});

it('answers a code request identically for an unknown account', function () {
    $this->postJson('/api/v1/auth/code', ['identifier' => 'nobody@example.com'])
        ->assertOk()
        ->assertJsonStructure(['message', 'sent_to']);

    expect(OtpCode::query()->count())->toBe(0);
});

it('registers a push token against the device', function () {
    $token = $this->user->createToken('Pixel 9')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/devices/push-token', [
        'push_token' => 'fcm-token-value',
        'platform' => 'android',
    ])->assertOk();

    expect($this->user->tokens()->first()->push_token)->toBe('fcm-token-value');
});

it('signs out this device only', function () {
    $keep = $this->user->createToken('Laptop')->plainTextToken;
    $drop = $this->user->createToken('Phone')->plainTextToken;

    $this->withToken($drop)->postJson('/api/v1/auth/logout')->assertOk();

    expect($this->user->tokens()->count())->toBe(1);
    $this->withToken($keep)->getJson('/api/v1/me')->assertOk();
});

it('signs out every device', function () {
    $this->user->createToken('Laptop');
    $token = $this->user->createToken('Phone')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/logout-all')->assertOk();

    expect($this->user->tokens()->count())->toBe(0);
});

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/v1/me')->assertStatus(401);
});
