<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('sends a reset link', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'aarti@example.com']);

    $this->post('/forgot-password', ['email' => 'aarti@example.com'])
        ->assertSessionHas('status');

    Notification::assertSentTo($user, ResetPassword::class);
});

it('reports the same thing for an unknown address', function () {
    Notification::fake();

    // Otherwise the form becomes a way to test which addresses are registered.
    $this->post('/forgot-password', ['email' => 'nobody@example.com'])
        ->assertSessionHas('status');

    Notification::assertNothingSent();
});

it('resets the password with a valid token', function () {
    $user = User::factory()->create(['email' => 'aarti@example.com']);
    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'aarti@example.com',
        'password' => 'BrandNewPass1!',
        'password_confirmation' => 'BrandNewPass1!',
    ])->assertRedirect('/login');

    expect(Hash::check('BrandNewPass1!', $user->fresh()->password))->toBeTrue();
});

it('refuses an invalid reset token', function () {
    $user = User::factory()->create(['email' => 'aarti@example.com', 'password' => 'Password123!']);

    $this->post('/reset-password', [
        'token' => 'not-a-real-token',
        'email' => 'aarti@example.com',
        'password' => 'BrandNewPass1!',
        'password_confirmation' => 'BrandNewPass1!',
    ])->assertInvalid('email');

    expect(Hash::check('Password123!', $user->fresh()->password))->toBeTrue();
});

it('forces a client created by an admin to set their own password', function () {
    $client = User::factory()->client()->mustChangePassword()->create(['password' => 'Issued123!']);

    // Until the issued password is replaced it also exists in an inbox and a
    // message log, so nothing else in the application should be reachable.
    $this->actingAs($client)->get('/client')->assertRedirect('/password/change');
});

it('lets that client through once the password is replaced', function () {
    $client = User::factory()->client()->mustChangePassword()->create(['password' => 'Issued123!']);

    $this->actingAs($client)->put('/password/change', [
        'current_password' => 'Issued123!',
        'password' => 'ChosenByMe1!',
        'password_confirmation' => 'ChosenByMe1!',
    ])->assertRedirect('/client');

    expect($client->fresh()->must_change_password)->toBeFalse();

    $this->actingAs($client->fresh())->get('/client')->assertOk();
});

it('refuses a password change without the current password', function () {
    $user = User::factory()->create(['password' => 'Password123!']);

    $this->actingAs($user)->put('/password/change', [
        'current_password' => 'not-it',
        'password' => 'ChosenByMe1!',
        'password_confirmation' => 'ChosenByMe1!',
    ])->assertInvalid('current_password');

    expect(Hash::check('Password123!', $user->fresh()->password))->toBeTrue();
});

it('refuses reusing the current password', function () {
    $user = User::factory()->create(['password' => 'Password123!']);

    $this->actingAs($user)->put('/password/change', [
        'current_password' => 'Password123!',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertInvalid('password');
});

it('does not ask a Google-only account for a current password', function () {
    $user = User::factory()->passwordless()->create();

    $this->actingAs($user)->put('/password/change', [
        'password' => 'FirstPassword1!',
        'password_confirmation' => 'FirstPassword1!',
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->hasPassword())->toBeTrue();
});
