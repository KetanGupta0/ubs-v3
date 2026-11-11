<?php

use App\Enums\AuthEvent;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\AuthAuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LiveSession;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->owner = User::factory()->owner()->create(['name' => 'Ketan Gupta']);
    $this->student = User::factory()->student()->create([
        'name' => 'Asha Verma',
        'last_login_at' => now()->subDays(3),
    ]);

    $this->course = Course::query()->create([
        'title' => 'Vue.js in depth', 'slug' => 'view-as-'.uniqid(), 'type' => 'course',
        'tagline' => 'x', 'summary' => 'y', 'price' => 0, 'is_published' => true,
    ]);
    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id, 'name' => 'Nov', 'code' => 'VA-'.uniqid(), 'starts_on' => today(),
    ]);
    Enrollment::query()->create([
        'user_id' => $this->student->id, 'course_id' => $this->course->id, 'batch_id' => $this->batch->id,
        'enrolled_at' => now(), 'has_paid' => true,
    ]);
});

function viewAs($test, ?User $staff = null, ?User $target = null, array $payload = ['reason' => 'They say lesson three will not open'])
{
    $target ??= $test->student;

    return $test->actingAs($staff ?? $test->owner)
        ->post("/admin/{$target->role->value}s/{$target->id}/view-as", $payload);
}

it('lets the owner see the platform as a student, with the reason on record in both logs', function () {
    $lastLogin = $this->student->last_login_at->toIso8601String();

    viewAs($this)->assertRedirect('/student');

    $this->assertAuthenticatedAs($this->student);

    $this->get('/student')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('viewingAs.name', 'Asha Verma')
            ->where('viewingAs.staff', 'Ketan Gupta'));

    $audit = AuditLog::query()->where('action', 'account.viewed_as')->sole();
    $theirs = AuthAuditLog::query()->where('event', AuthEvent::ViewedAsStarted)->sole();

    expect($audit->actor_id)->toBe($this->owner->id)
        ->and($audit->changes['reason'])->toBe('They say lesson three will not open')
        ->and($theirs->user_id)->toBe($this->student->id)
        // It is not a sign in, so their last sign in is still their own.
        ->and($this->student->fresh()->last_login_at->toIso8601String())->toBe($lastLogin)
        ->and(AuthAuditLog::query()->where('event', AuthEvent::LoginSucceeded)->count())->toBe(0);
});

it('refuses every change while viewing', function () {
    $other = Course::query()->create([
        'title' => 'Free course', 'slug' => 'free-'.uniqid(), 'type' => 'course',
        'tagline' => 'x', 'summary' => 'y', 'price' => 0, 'is_published' => true,
    ]);

    viewAs($this);

    $this->from('/student/catalogue')
        ->post('/student/enrol', ['course_id' => $other->id])
        ->assertRedirect('/student/catalogue')
        ->assertSessionHas('error', 'Viewing as somebody is read only. Nothing was changed.');

    $this->postJson('/student/enrol', ['course_id' => $other->id])->assertForbidden();
    $this->put('/password/change', ['password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])->assertSessionHas('error');
    $this->post('/logout')->assertSessionHas('error');

    expect(Enrollment::query()->where('course_id', $other->id)->count())->toBe(0);
    $this->assertAuthenticatedAs($this->student);
});

it('keeps chat closed, and leaves the student\'s activity record alone', function () {
    $session = LiveSession::query()->create([
        'batch_id' => $this->batch->id, 'title' => 'Components', 'scheduled_at' => now(),
        'duration_minutes' => 90, 'meet_link' => 'https://meet.example.test/abc',
    ]);

    viewAs($this);

    $this->get('/chat')->assertRedirect('/student');

    // Joining a class normally notes that the student joined. Not now.
    $this->get("/student/classes/{$session->id}/join")->assertRedirect('https://meet.example.test/abc');
    expect(ActivityLog::query()->count())->toBe(0);
});

it('notes the same visit when it really is the student', function () {
    $session = LiveSession::query()->create([
        'batch_id' => $this->batch->id, 'title' => 'Components', 'scheduled_at' => now(),
        'duration_minutes' => 90, 'meet_link' => 'https://meet.example.test/abc',
    ]);

    $this->actingAs($this->student)->get("/student/classes/{$session->id}/join");

    expect(ActivityLog::query()->where('action', 'session.joined')->count())->toBe(1);
});

it('goes back to the member of staff, on that person\'s profile', function () {
    viewAs($this);

    $this->post('/viewing-as/stop')
        ->assertRedirect("/admin/students/{$this->student->id}")
        ->assertSessionHas('success');

    $this->assertAuthenticatedAs($this->owner);

    expect(AuthAuditLog::query()->where('event', AuthEvent::ViewedAsStopped)->count())->toBe(1);

    $this->get('/admin')->assertInertia(fn ($page) => $page->where('viewingAs', null));
});

it('ends by itself after half an hour', function () {
    viewAs($this);

    $this->travel(31)->minutes();

    $this->get('/student')->assertRedirect('/admin');
    $this->assertAuthenticatedAs($this->owner);
});

it('does not push the member of staff into choosing the student\'s password', function () {
    $this->student->forceFill(['must_change_password' => true])->save();

    viewAs($this);

    $this->get('/student')->assertOk();
});

it('cannot be used on an administrator, without the permission, or without a reason', function () {
    $staff = User::factory()->admin()->create();

    // Not a route for administrators at all.
    $this->actingAs($this->owner)->post("/admin/admins/{$staff->id}/view-as", ['reason' => 'Curious'])->assertNotFound();

    // Being able to edit students is not being trusted to see as them.
    $editor = User::factory()->admin()->create();
    $editor->permissions()->attach(Permission::query()->whereIn('key', ['students.view', 'students.update'])->get());
    viewAs($this, $editor->fresh())->assertForbidden();

    viewAs($this, payload: ['reason' => ''])->assertSessionHasErrors('reason');
    $this->assertAuthenticatedAs($this->owner);

    expect(AuditLog::query()->where('action', 'account.viewed_as')->count())->toBe(0);
});

it('signs everybody out if the member of staff was suspended meanwhile', function () {
    $helper = User::factory()->admin()->create();
    $helper->permissions()->attach(Permission::query()->whereIn('key', ['students.view', 'people.view_as'])->get());

    viewAs($this, $helper->fresh());
    $helper->forceFill(['status' => 'suspended'])->save();

    $this->post('/viewing-as/stop')->assertRedirect('/login');
    $this->assertGuest();
});
