<?php

use App\Models\User;
use App\Rules\PersonName;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * Bots signing up to have our mail carry their advert (2026-11-21): three
 * "clients" named "🔥 70.000 TL … https://bit.ly/…" came from v2. A name is
 * letters only, everywhere a person is named, and the student sign up form has
 * the honeypot and the human check the other account forms already had.
 */

function signUp(array $overrides = []): TestResponse
{
    return test()->post('/register', [
        'name' => 'Rahul Verma',
        'email' => 'rahul@example.com',
        'mobile' => '98765 43210',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'terms' => true,
        ...$overrides,
    ]);
}

it('takes real names in any script and refuses links, addresses, numbers and emoji', function () {
    foreach (['Ritu Raj', "O'Brien", 'A. K. Singh', 'A.K. Singh', 'Anne-Marie', 'राम कुमार', 'Mohd. Zaid Khan', 'ASHISH PRASAD KESRI'] as $name) {
        expect(PersonName::looksLikeAName($name))->toBeTrue($name);
    }

    foreach (['🔥 70.000 TL Sonraki Hamleni Degistirebilir https://bit.ly/4z7oyMl 🔥', 'Visit www.example.com', 'Free bit.ly link', 'Mohd.Zaid', 'me@example.com', 'Agent 47', 'x', 'Rahul 🔥', 'Buy <b>now</b>'] as $name) {
        expect(PersonName::looksLikeAName($name))->toBeFalse($name);
    }
});

it('refuses a sign up whose name carries a link, and makes nothing', function () {
    Event::fake([Registered::class]);

    signUp(['name' => '🔥 70.000 TL Sonraki Hamleni https://bit.ly/4z7oyMl'])->assertSessionHasErrors(['name' => PersonName::MESSAGE]);

    expect(User::query()->count())->toBe(0);
    Event::assertNotDispatched(Registered::class);
});

it('refuses a sign up that fills the hidden field', function () {
    signUp(['website' => 'https://spam.example'])->assertSessionHasErrors('website');

    expect(User::query()->count())->toBe(0);
});

it('asks Cloudflare when the human check is on, and refuses a failed one', function () {
    config(['services.turnstile.site_key' => 'site', 'services.turnstile.secret' => 'secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::sequence()
        ->push(['success' => false])
        ->push(['success' => true])]);

    $this->get('/register')->assertInertia(fn ($page) => $page->where('humanCheck', 'site'));

    signUp(['cf-turnstile-response' => 'token'])->assertSessionHasErrors('human');
    expect(User::query()->count())->toBe(0);

    signUp(['cf-turnstile-response' => 'token'])->assertRedirect('/student');
    expect(User::query()->count())->toBe(1);
});

it('refuses the same name on the forms staff and clients use to add people', function () {
    $owner = User::factory()->owner()->create();
    $student = User::factory()->student()->create();

    $this->actingAs($owner)
        ->put("/admin/students/{$student->id}", ['name' => 'Win big at bit.ly/x', 'email' => $student->email])
        ->assertSessionHasErrors(['name' => PersonName::MESSAGE]);
});
