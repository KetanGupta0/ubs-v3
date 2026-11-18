<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/** A notification as the database channel writes one. */
function storedNotification(User $user, array $data, bool $read = false): DatabaseNotification
{
    return DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\Example',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => $data,
        'read_at' => $read ? now() : null,
    ]);
}

beforeEach(function () {
    $this->student = User::factory()->create(['role' => Role::Student]);
    $this->other = User::factory()->create(['role' => Role::Student]);
});

it('shows what the platform told somebody, with how many are unread', function () {
    storedNotification($this->student, ['kind' => 'order.paid', 'title' => 'Your order is paid', 'url' => '/student']);
    storedNotification($this->student, ['kind' => 'ticket.raised', 'subject' => 'Cannot sign in', 'url' => '/student/support'], read: true);
    storedNotification($this->student, ['kind' => 'renewal_due']);
    storedNotification($this->other, ['kind' => 'x', 'title' => 'Somebody else\'s', 'url' => '/student']);

    $this->actingAs($this->student)->get('/student')->assertInertia(fn ($page) => $page->where('auth.unreadNotifications', 2));

    $recent = $this->actingAs($this->student)->getJson('/notifications/recent')->assertOk()->json();
    expect($recent['unread'])->toBe(2)
        ->and(collect($recent['items'])->pluck('title')->sort()->values()->all())->toBe(['Cannot sign in', 'Renewal due', 'Your order is paid']);

    $this->actingAs($this->student)->get('/notifications')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Notifications')->has('table.rows', 3));
    $this->actingAs($this->student)->get('/notifications?filter[read]=unread')
        ->assertInertia(fn ($page) => $page->has('table.rows', 2));
});

it('marks one read as it is opened and goes where it points, only on this site', function () {
    $mine = storedNotification($this->student, ['title' => 'Paid', 'url' => '/student/payments']);
    $elsewhere = storedNotification($this->student, ['title' => 'Odd', 'url' => 'https://example.com/phish']);
    $theirs = storedNotification($this->other, ['title' => 'Theirs', 'url' => '/student']);

    $this->actingAs($this->student)->post("/notifications/{$mine->id}")->assertRedirect('/student/payments');
    expect($mine->fresh()->read_at)->not->toBeNull();

    $this->actingAs($this->student)->from('/student')->post("/notifications/{$elsewhere->id}")->assertRedirect('/student');
    $this->actingAs($this->student)->post("/notifications/{$theirs->id}")->assertNotFound();
    expect($theirs->fresh()->read_at)->toBeNull();

    storedNotification($this->student, ['title' => 'Another']);
    $this->actingAs($this->student)->post('/notifications/read-all');
    expect($this->student->unreadNotifications()->count())->toBe(0)
        ->and($this->other->unreadNotifications()->count())->toBe(1);
});
