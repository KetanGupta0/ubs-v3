<?php

use App\Enums\AuthEvent;
use App\Models\AuthAuditLog;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'aarti@example.com',
        'mobile' => '+919876543210',
        'password' => 'Password123!',
    ]);
});

it('shows the sign in screen', function () {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/Login'));
});

it('signs in with an email address', function () {
    $this->post('/login', [
        'identifier' => 'aarti@example.com',
        'password' => 'Password123!',
    ])->assertRedirect('/student');

    $this->assertAuthenticatedAs($this->user);
});

it('signs in with a mobile number', function () {
    $this->post('/login', [
        'identifier' => '+919876543210',
        'password' => 'Password123!',
    ])->assertRedirect('/student');

    $this->assertAuthenticated();
});

it('accepts a mobile number typed without a country code', function () {
    $this->post('/login', [
        'identifier' => '98765 43210',
        'password' => 'Password123!',
    ])->assertRedirect('/student');

    $this->assertAuthenticated();
});

it('gives the same error whether the account exists or the password is wrong', function () {
    // A different message either way would turn this form into a way to
    // discover which addresses and numbers are registered.
    $expected = 'Those details do not match any account.';

    $this->post('/login', [
        'identifier' => 'aarti@example.com',
        'password' => 'NotThePassword1!',
    ])->assertInvalid(['identifier' => $expected]);

    $this->post('/login', [
        'identifier' => 'nobody@example.com',
        'password' => 'NotThePassword1!',
    ])->assertInvalid(['identifier' => $expected]);

    $this->assertGuest();
});

it('refuses a suspended account', function () {
    $suspended = User::factory()->suspended()->create(['password' => 'Password123!']);

    $this->post('/login', [
        'identifier' => $suspended->email,
        'password' => 'Password123!',
    ])->assertSessionHasErrors('identifier');

    $this->assertGuest();
});

it('locks out after repeated failures', function () {
    foreach (range(1, 5) as $ignored) {
        $this->post('/login', [
            'identifier' => 'aarti@example.com',
            'password' => 'wrong-password',
        ]);
    }

    // Even the correct password is refused while the lockout holds.
    $this->post('/login', [
        'identifier' => 'aarti@example.com',
        'password' => 'Password123!',
    ])->assertSessionHasErrors('identifier');

    $this->assertGuest();

    expect(AuthAuditLog::query()->where('event', AuthEvent::LoginThrottled->value)->exists())->toBeTrue();
});

it('sends each role to its own dashboard', function () {
    $cases = [
        [User::factory()->admin()->create(['password' => 'Password123!']), '/admin'],
        [User::factory()->client()->create(['password' => 'Password123!']), '/client'],
        [User::factory()->student()->create(['password' => 'Password123!']), '/student'],
    ];

    foreach ($cases as [$user, $destination]) {
        $this->post('/login', ['identifier' => $user->email, 'password' => 'Password123!'])
            ->assertRedirect($destination);

        $this->post('/logout');
    }
});

it('records a successful sign in without storing the plain identifier', function () {
    $this->post('/login', [
        'identifier' => 'aarti@example.com',
        'password' => 'Password123!',
    ]);

    $log = AuthAuditLog::query()->where('event', AuthEvent::LoginSucceeded->value)->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->method)->toBe('password')
        ->and($log->identifier)->not->toBe('aarti@example.com');

    expect($this->user->fresh()->last_login_at)->not->toBeNull();
});

it('signs out', function () {
    $this->actingAs($this->user)->post('/logout')->assertRedirect('/');

    $this->assertGuest();
});

it('keeps a signed in person away from the sign in screen', function () {
    $this->actingAs($this->user)->get('/login')->assertRedirect('/student');
});
