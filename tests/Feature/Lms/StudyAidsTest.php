<?php

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\LessonNote;
use App\Models\LessonVisit;
use App\Models\LiveSession;
use App\Models\User;
use App\Notifications\RecordingAvailable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->student = User::factory()->student()->create();

    $this->course = Course::query()->create([
        'title' => 'Python basics', 'slug' => 'study-'.uniqid(), 'type' => 'course',
        'tagline' => 'From zero', 'summary' => 'Six weeks.',
    ]);
    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id, 'name' => 'Morning batch', 'code' => 'ST-'.uniqid(), 'starts_on' => today()->subWeek(),
    ]);
    $this->enrolment = Enrollment::query()->create([
        'user_id' => $this->student->id, 'course_id' => $this->course->id, 'batch_id' => $this->batch->id,
        'enrolled_at' => now()->subWeek(), 'has_paid' => true,
    ]);

    $first = CourseModule::query()->create(['course_id' => $this->course->id, 'title' => 'Week one', 'sort_order' => 1]);
    $second = CourseModule::query()->create(['course_id' => $this->course->id, 'title' => 'Week two', 'sort_order' => 2]);

    $make = fn (CourseModule $module, string $title, int $order) => Lesson::query()->create([
        'course_id' => $this->course->id, 'course_module_id' => $module->id,
        'title' => $title, 'slug' => str($title)->slug().'-'.uniqid(), 'sort_order' => $order,
    ]);

    $this->one = $make($first, 'Variables', 1);
    $this->two = $make($first, 'Loops', 2);
    $this->three = $make($second, 'Functions', 1);

    $this->lessonPath = fn (Lesson $lesson) => "/student/courses/{$this->course->id}/lessons/{$lesson->id}";
});

/* -------------------------------------------------------------- visits */

it('records opening a lesson, writing the activity log once a day', function () {
    $this->actingAs($this->student)->get(($this->lessonPath)($this->one))->assertOk();
    $this->actingAs($this->student)->get(($this->lessonPath)($this->one))->assertOk();

    $visit = LessonVisit::query()->sole();
    expect($visit->visits)->toBe(2)
        ->and(ActivityLog::query()->where('action', 'lesson.opened')->count())->toBe(1);

    $this->travel(1)->days();
    $this->actingAs($this->student)->get(($this->lessonPath)($this->one));

    expect(ActivityLog::query()->where('action', 'lesson.opened')->count())->toBe(2);
});

it('adds time from the page in capped beats, and shows it in a coarse bucket', function () {
    $this->actingAs($this->student)->get(($this->lessonPath)($this->one));

    $this->actingAs($this->student)->postJson(($this->lessonPath)($this->one).'/time', ['seconds' => 120])->assertNoContent();
    $this->actingAs($this->student)->postJson(($this->lessonPath)($this->one).'/time', ['seconds' => 3000])->assertNoContent();
    $this->actingAs($this->student)->postJson(($this->lessonPath)($this->one).'/time', ['seconds' => 3000])->assertNoContent();

    expect(LessonVisit::query()->sole()->seconds_spent)->toBe(120 + 2 * LessonVisit::MAX_BEAT_SECONDS);

    $this->actingAs($this->student)->get(($this->lessonPath)($this->one))
        ->assertInertia(fn ($page) => $page->where('timeSpent', '5 to 15 minutes'));

    expect(LessonVisit::bucketFor(30))->toBe('Under 5 minutes')
        ->and(LessonVisit::bucketFor(3 * 3600))->toBe('Over an hour');
});

it('keeps the time when the lesson is finished', function () {
    $this->actingAs($this->student)->get(($this->lessonPath)($this->one));
    $this->actingAs($this->student)->postJson(($this->lessonPath)($this->one).'/time', ['seconds' => 100]);
    $this->actingAs($this->student)->post(($this->lessonPath)($this->one).'/complete');

    expect(LessonCompletion::query()->sole()->seconds_spent)->toBe(100);
});

it('takes no time or note for a lesson the gate has not opened, or a course they are not on', function () {
    $this->three->update(['prerequisite_lesson_id' => $this->two->id]);
    $stranger = User::factory()->student()->create();

    $this->actingAs($this->student)->postJson(($this->lessonPath)($this->three).'/time', ['seconds' => 60])->assertForbidden();
    $this->actingAs($this->student)->put(($this->lessonPath)($this->three).'/note', ['body' => 'x'])->assertForbidden();
    $this->actingAs($stranger)->put(($this->lessonPath)($this->one).'/note', ['body' => 'x'])->assertNotFound();

    expect(LessonNote::query()->count())->toBe(0);
});

/* ---------------------------------------------------- notes and bookmarks */

it('saves a note and a bookmark, and removes the row when both are cleared', function () {
    $this->actingAs($this->student)->put(($this->lessonPath)($this->one).'/note', ['body' => '  Remember range() stops early.  ', 'bookmarked' => true])
        ->assertRedirect();

    $note = LessonNote::query()->sole();
    expect($note->body)->toBe('Remember range() stops early.')->and($note->is_bookmarked)->toBeTrue();

    $this->actingAs($this->student)->get(($this->lessonPath)($this->one))
        ->assertInertia(fn ($page) => $page->where('note.body', 'Remember range() stops early.')->where('note.bookmarked', true));
    $this->actingAs($this->student)->get("/student/courses/{$this->course->id}")
        ->assertInertia(fn ($page) => $page->where('modules.0.lessons.0.bookmarked', true)->where('modules.0.lessons.1.bookmarked', false));

    $this->actingAs($this->student)->put(($this->lessonPath)($this->one).'/note', ['body' => '', 'bookmarked' => false]);
    expect(LessonNote::query()->count())->toBe(0);
});

it('lists a student\'s own notes and nobody else\'s', function () {
    LessonNote::query()->create(['user_id' => $this->student->id, 'lesson_id' => $this->two->id, 'course_id' => $this->course->id, 'body' => 'Mine']);
    $other = User::factory()->student()->create();
    LessonNote::query()->create(['user_id' => $other->id, 'lesson_id' => $this->two->id, 'course_id' => $this->course->id, 'body' => 'Theirs']);

    $this->actingAs($this->student)->get('/student/notes')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('student/Notes')
            ->has('notes', 1)
            ->where('notes.0.body', 'Mine')
            ->where('notes.0.lesson', 'Loops')
            ->where('notes.0.module', 'Week one'));
});

it('keeps notes private while staff view the platform as the student', function () {
    LessonNote::query()->create(['user_id' => $this->student->id, 'lesson_id' => $this->one->id, 'course_id' => $this->course->id, 'body' => 'Private thought', 'is_bookmarked' => true]);

    $this->actingAs($this->owner)->post("/admin/students/{$this->student->id}/view-as", ['reason' => 'Checking lesson access'])->assertRedirect('/student');

    $this->get('/student/notes')->assertInertia(fn ($page) => $page->where('private', true)->has('notes', 0));
    $this->get(($this->lessonPath)($this->one))->assertInertia(fn ($page) => $page->where('note.body', '')->where('note.private', false));
    $this->get("/student/courses/{$this->course->id}")->assertInertia(fn ($page) => $page->where('modules.0.lessons.0.bookmarked', false));

    // And looking does not count as the student studying.
    expect(LessonVisit::query()->count())->toBe(0);
});

/* ------------------------------------------------------- where to go next */

it('offers the last lesson opened and not finished, then the first not done', function () {
    $this->actingAs($this->student)->get('/student')
        ->assertInertia(fn ($page) => $page->where('continue.kind', 'start')->where('continue.lesson', 'Variables'));

    $this->actingAs($this->student)->get(($this->lessonPath)($this->two));
    $this->actingAs($this->student)->get('/student')
        ->assertInertia(fn ($page) => $page
            ->where('continue.kind', 'continue')
            ->where('continue.lesson', 'Loops')
            ->where('continue.href', ($this->lessonPath)($this->two)));

    $this->actingAs($this->student)->post(($this->lessonPath)($this->two).'/complete');
    $this->actingAs($this->student)->post(($this->lessonPath)($this->one).'/complete');

    $this->actingAs($this->student)->get('/student')
        ->assertInertia(fn ($page) => $page->where('continue.kind', 'start')->where('continue.lesson', 'Functions'));

    $this->actingAs($this->student)->post(($this->lessonPath)($this->three).'/complete');
    $this->actingAs($this->student)->get('/student')->assertInertia(fn ($page) => $page->where('continue', null));
});

/* ------------------------------------------------------------ recordings */

it('tells the batch once when a recording goes up, and keeps a class to its own course', function () {
    Notification::fake();
    $classmate = User::factory()->student()->create();
    Enrollment::query()->create([
        'user_id' => $classmate->id, 'course_id' => $this->course->id, 'batch_id' => $this->batch->id,
        'enrolled_at' => now(), 'has_paid' => true,
    ]);
    $dropped = User::factory()->student()->create();
    Enrollment::query()->create([
        'user_id' => $dropped->id, 'course_id' => $this->course->id, 'batch_id' => $this->batch->id,
        'enrolled_at' => now(), 'has_paid' => true, 'status' => 'dropped',
    ]);

    $session = LiveSession::query()->create([
        'batch_id' => $this->batch->id, 'title' => 'Loops, live', 'scheduled_at' => now()->subDay(), 'duration_minutes' => 60,
    ]);
    $path = "/admin/batches/{$this->batch->getRouteKey()}/sessions/{$session->id}";
    $payload = ['title' => 'Loops, live', 'scheduled_at' => now()->subDay()->toDateTimeString(), 'duration_minutes' => 60, 'lesson_id' => $this->two->id];

    $other = Course::query()->create(['title' => 'Other', 'slug' => 'other-'.uniqid(), 'tagline' => 'x', 'summary' => 'x']);
    $otherModule = CourseModule::query()->create(['course_id' => $other->id, 'title' => 'X']);
    $foreign = Lesson::query()->create(['course_id' => $other->id, 'course_module_id' => $otherModule->id, 'title' => 'Foreign', 'slug' => 'foreign-'.uniqid()]);

    $this->actingAs($this->owner)->put($path, [...$payload, 'lesson_id' => $foreign->id])->assertSessionHasErrors('lesson_id');
    $this->actingAs($this->owner)->put($path, [...$payload, 'recording_url' => 'javascript:alert(1)'])->assertSessionHasErrors('recording_url');

    $this->actingAs($this->owner)->put($path, [...$payload, 'recording_url' => 'https://youtu.be/abcdefghijk'])->assertSessionHas('success');
    $this->actingAs($this->owner)->put($path, [...$payload, 'recording_url' => 'https://youtu.be/abcdefghijz'])->assertSessionHas('success');

    Notification::assertSentToTimes($this->student, RecordingAvailable::class, 1);
    Notification::assertSentToTimes($classmate, RecordingAvailable::class, 1);
    Notification::assertNotSentTo($dropped, RecordingAvailable::class);
    expect(AuditLog::query()->where('subject_type', LiveSession::class)->where('subject_id', $session->id)->count())->toBe(2);

    // On the lesson it taught, and on the dashboard of somebody not marked present.
    $this->actingAs($this->student)->get(($this->lessonPath)($this->two))
        ->assertInertia(fn ($page) => $page->has('recordings', 1)->where('recordings.0.url', 'https://youtu.be/abcdefghijz'));
    $this->actingAs($this->student)->get('/student')
        ->assertInertia(fn ($page) => $page->has('missedRecordings', 1)->where('missedRecordings.0.title', 'Loops, live'));

    Attendance::query()->create(['live_session_id' => $session->id, 'user_id' => $this->student->id, 'status' => 'present']);
    $this->actingAs($this->student)->get('/student')->assertInertia(fn ($page) => $page->has('missedRecordings', 0));
});

it('keeps a class\'s lesson and status when an edit does not mention them', function () {
    $session = LiveSession::query()->create([
        'batch_id' => $this->batch->id, 'title' => 'Loops, live', 'scheduled_at' => now()->subDay(),
        'duration_minutes' => 60, 'lesson_id' => $this->two->id, 'status' => 'held',
    ]);

    $this->actingAs($this->owner)->put("/admin/batches/{$this->batch->getRouteKey()}/sessions/{$session->id}", [
        'title' => 'Loops, live (renamed)', 'scheduled_at' => now()->subDay()->toDateTimeString(), 'duration_minutes' => 60,
    ])->assertSessionHas('success');

    expect($session->fresh())
        ->title->toBe('Loops, live (renamed)')
        ->lesson_id->toBe($this->two->id)
        ->status->toBe('held');

    $this->actingAs($this->owner)->put("/admin/batches/{$this->batch->getRouteKey()}/sessions/{$session->id}", [
        'title' => 'Loops, live', 'scheduled_at' => now()->subDay()->toDateTimeString(), 'duration_minutes' => 60, 'lesson_id' => null,
    ]);
    expect($session->fresh()->lesson_id)->toBeNull();
});
