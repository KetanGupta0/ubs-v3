<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;

it('shows the registration screen', function () {
    $this->get('/register')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/Register'));
});

it('creates a student account', function () {
    Event::fake([Registered::class]);

    $this->post('/register', [
        'name' => 'Rahul Verma',
        'email' => 'rahul@example.com',
        'mobile' => '98765 43210',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'terms' => true,
    ])->assertRedirect('/student');

    $user = User::query()->where('email', 'rahul@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(Role::Student)
        // The number is stored in one canonical form whatever was typed.
        ->and($user->mobile)->toBe('+919876543210')
        ->and($user->profile)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
    Event::assertDispatched(Registered::class);
});

it('ignores a role sent in the request', function () {
    $this->post('/register', [
        'name' => 'Opportunist',
        'email' => 'sneaky@example.com',
        'mobile' => '9000000111',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'terms' => true,
        'role' => 'admin',
    ]);

    // Only students may self register, so the posted role must have no effect.
    expect(User::query()->where('email', 'sneaky@example.com')->first()->role)
        ->toBe(Role::Student);
});

it('rejects a duplicate email or mobile number', function () {
    User::factory()->create(['email' => 'taken@example.com', 'mobile' => '+919111111111']);

    $this->post('/register', [
        'name' => 'Someone',
        'email' => 'taken@example.com',
        'mobile' => '9222222222',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'terms' => true,
    ])->assertInvalid('email');

    $this->post('/register', [
        'name' => 'Someone',
        'email' => 'fresh@example.com',
        'mobile' => '9111111111',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'terms' => true,
    ])->assertInvalid('mobile');
});

it('enforces the password policy', function () {
    $this->post('/register', [
        'name' => 'Someone',
        'email' => 'weak@example.com',
        'mobile' => '9333333333',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => true,
    ])->assertInvalid('password');

    expect(User::query()->where('email', 'weak@example.com')->exists())->toBeFalse();
});

it('requires the terms to be accepted', function () {
    $this->post('/register', [
        'name' => 'Someone',
        'email' => 'noterms@example.com',
        'mobile' => '9444444444',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ])->assertInvalid('terms');
});
