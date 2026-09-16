<?php

use App\Models\Lead;
use App\Models\User;
use App\Notifications\WelcomeCredentials;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
});

function makeLead(array $attributes = []): Lead
{
    return Lead::query()->create(array_merge([
        'name' => 'Priya Nair',
        'email' => 'priya@example.test',
        'mobile' => '+919812345671',
        'company' => 'Nair Textiles',
        'message' => 'We need a billing system.',
    ], $attributes));
}

it('gives every enquiry a reference the visitor can quote back', function () {
    expect(makeLead()->reference)->not->toBeEmpty();
});

it('turns an enquiry into a client account and links the two', function () {
    Notification::fake();

    $lead = makeLead();

    $this->actingAs($this->owner)->post("/admin/leads/{$lead->id}/convert", [
        'name' => 'Priya Nair',
        'email' => 'priya@example.test',
        'mobile' => '+919812345671',
        'company' => 'Nair Textiles',
        'role' => 'client',
    ])->assertRedirect();

    $client = User::query()->where('email', 'priya@example.test')->sole();

    expect($lead->fresh()->converted_user_id)->toBe($client->id)
        ->and($lead->fresh()->status)->toBe('converted');

    Notification::assertSentTo($client, WelcomeCredentials::class);
});

it('will not convert the same enquiry twice', function () {
    Notification::fake();

    $lead = makeLead(['converted_user_id' => User::factory()->client()->create()->id]);

    $this->actingAs($this->owner)->post("/admin/leads/{$lead->id}/convert", [
        'name' => 'Priya Nair',
        'email' => 'someone-else@example.test',
        'role' => 'client',
    ]);

    expect(User::query()->where('email', 'someone-else@example.test')->exists())->toBeFalse();
});

it('says plainly when an account already uses that address', function () {
    User::factory()->client()->create(['email' => 'priya@example.test']);
    $lead = makeLead();

    $this->actingAs($this->owner)->post("/admin/leads/{$lead->id}/convert", [
        'name' => 'Priya Nair',
        'email' => 'priya@example.test',
        'role' => 'client',
    ])->assertSessionHasErrors(['email' => 'An account already uses that email address.']);
});

it('records a note against the enquiry with its author', function () {
    $lead = makeLead();

    $this->actingAs($this->owner)
        ->post("/admin/leads/{$lead->id}/notes", ['body' => 'Called. Wants a demo on Friday.'])
        ->assertRedirect();

    $this->assertDatabaseHas('lead_notes', [
        'lead_id' => $lead->id,
        'author_id' => $this->owner->id,
        'body' => 'Called. Wants a demo on Friday.',
    ]);
});

it('will not assign an enquiry to somebody who is not staff', function () {
    $lead = makeLead();
    $student = User::factory()->student()->create();

    $this->actingAs($this->owner)
        ->put("/admin/leads/{$lead->id}", ['assigned_to' => $student->id])
        ->assertSessionHasErrors('assigned_to');
});
