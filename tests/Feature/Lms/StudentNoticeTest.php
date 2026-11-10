<?php

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Permission;
use App\Models\User;
use App\Services\Lms\Enroller;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(PermissionSeeder::class);

    $this->owner = User::factory()->owner()->create();
    $this->asha = User::factory()->student()->create(['name' => 'Asha Verma']);
    $this->imran = User::factory()->student()->create(['name' => 'Imran Qureshi']);

    // Both on the same batch, so nothing but the addressee separates them.
    $course = Course::query()->create([
        'title' => 'Vue.js in depth', 'slug' => 'notice-course-'.uniqid(), 'type' => 'course',
        'tagline' => 'x', 'summary' => 'y', 'price' => 0, 'is_published' => true,
    ]);
    $batch = Batch::query()->create([
        'course_id' => $course->id, 'name' => 'Vue November', 'code' => 'NT-'.uniqid(),
        'starts_on' => today(), 'status' => 'running', 'is_published' => true,
    ]);
    foreach ([$this->asha, $this->imran] as $student) {
        app(Enroller::class)->enrol($student, $course, $batch, notify: false);
    }
});

function sendNotice($test, User $actor, User $to, array $payload = [])
{
    return $test->actingAs($actor)->post("/admin/students/{$to->id}/notices", [
        'title' => 'Your project review is on Friday',
        'body' => 'Bring the deployed link. Ten minutes each.',
        ...$payload,
    ]);
}

it('sends a notice to one student, which only that student sees', function () {
    sendNotice($this, $this->owner, $this->asha)
        ->assertSessionHas('success', 'Sent to Asha Verma. It is on their dashboard now.');

    $this->actingAs($this->asha)->get('/student/announcements')
        ->assertInertia(fn ($page) => $page
            ->where('announcements.0.title', 'Your project review is on Friday')
            ->where('announcements.0.scope', 'Just for you'));

    $this->actingAs($this->imran)->get('/student/announcements')
        ->assertInertia(fn ($page) => $page->has('announcements', 0));

    $this->actingAs($this->imran)->get('/student')
        ->assertDontSee('Your project review is on Friday');

    expect(AuditLog::query()->where('action', 'notice.sent')->exists())->toBeTrue();
});

it('keeps it out of everybody else\'s announcements in the mobile API too', function () {
    sendNotice($this, $this->owner, $this->asha);

    // The owner's web session from sending it would otherwise answer first.
    $this->app['auth']->forgetGuards();

    $token = $this->imran->createToken('phone', ['*'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/student/announcements')
        ->assertOk()
        ->assertJsonMissing(['title' => 'Your project review is on Friday']);

    // And it does reach the student it was meant for.
    $this->app['auth']->forgetGuards();

    $this->withToken($this->asha->createToken('phone', ['*'])->plainTextToken)
        ->getJson('/api/v1/student/announcements')
        ->assertOk()
        ->assertJsonFragment(['title' => 'Your project review is on Friday']);
});

it('lists the notices on the student\'s admin profile, and can withdraw one', function () {
    sendNotice($this, $this->owner, $this->asha, ['is_pinned' => true]);
    $notice = Announcement::query()->sole();

    $this->actingAs($this->owner)->get("/admin/students/{$this->asha->id}")
        ->assertInertia(fn ($page) => $page
            ->where('canManageStudent', true)
            ->has('record.notices', 1)
            ->where('record.notices.0.pinned', true));

    $this->actingAs($this->owner)->delete("/admin/students/{$this->asha->id}/notices/{$notice->id}")
        ->assertSessionHas('success', 'Notice withdrawn.');

    expect(Announcement::query()->count())->toBe(0);
});

it('will not withdraw a notice through somebody else\'s profile', function () {
    sendNotice($this, $this->owner, $this->asha);
    $notice = Announcement::query()->sole();

    $this->actingAs($this->owner)->delete("/admin/students/{$this->imran->id}/notices/{$notice->id}")->assertNotFound();

    expect(Announcement::query()->count())->toBe(1);
});

it('only sends to students, and only for staff who may update them', function () {
    sendNotice($this, $this->owner, User::factory()->client()->create())->assertNotFound();

    $viewer = User::factory()->admin()->create();
    $viewer->permissions()->attach(Permission::query()->where('key', 'students.view')->sole());

    sendNotice($this, $viewer->fresh(), $this->asha)->assertForbidden();
});
