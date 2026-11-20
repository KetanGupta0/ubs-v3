<?php

use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LeaderboardPoint;
use App\Models\LiveSession;
use App\Models\User;
use App\Services\Lms\ResultCard;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->student = User::factory()->student()->create();

    $this->course = Course::query()->create([
        'title' => 'Web development internship',
        'slug' => 'attendance-course-'.uniqid(),
        'type' => 'internship',
        'tagline' => 'Build something real',
        'summary' => 'Eight weeks, live.',
        'minimum_attendance' => 75,
    ]);

    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id,
        'name' => 'September batch',
        'code' => 'AT-'.uniqid(),
        'starts_on' => today()->subWeeks(2),
        'meet_link' => 'https://meet.google.com/abc-defg-hij',
    ]);

    $this->enrolment = Enrollment::query()->create([
        'user_id' => $this->student->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'enrolled_at' => now()->subWeeks(2),
        'has_paid' => true,
    ]);
});

function liveClass(int $daysAgo = 1, array $attributes = []): LiveSession
{
    return LiveSession::query()->create([
        'batch_id' => test()->batch->id,
        'title' => 'Session '.uniqid(),
        'scheduled_at' => now()->subDays($daysAgo)->setTime(19, 30),
        'duration_minutes' => 90,
        ...$attributes,
    ]);
}

it('awards the attendance points with the mark, once', function () {
    $held = liveClass();

    $mark = fn () => $this->actingAs($this->owner)
        ->post("/admin/batches/{$this->batch->id}/sessions/{$held->id}/register", [
            'marks' => [['user_id' => $this->student->id, 'status' => 'present']],
        ]);

    $mark()->assertRedirect();
    $mark()->assertRedirect();

    expect(Attendance::query()->count())->toBe(1)
        ->and(LeaderboardPoint::query()->where('user_id', $this->student->id)->count())->toBe(1);
});

it('counts a late arrival as attended', function () {
    $held = liveClass();

    $this->actingAs($this->owner)
        ->post("/admin/batches/{$this->batch->id}/sessions/{$held->id}/register", [
            'marks' => [['user_id' => $this->student->id, 'status' => 'late']],
        ]);

    $result = app(ResultCard::class)->for($this->enrolment->fresh());

    expect($result['attendance']['percent'])->toBe(100.0);
});

it('ignores a mark for somebody who is not on the batch', function () {
    $stranger = User::factory()->student()->create();
    $held = liveClass();

    $this->actingAs($this->owner)
        ->post("/admin/batches/{$this->batch->id}/sessions/{$held->id}/register", [
            'marks' => [['user_id' => $stranger->id, 'status' => 'present']],
        ]);

    expect(Attendance::query()->where('user_id', $stranger->id)->count())->toBe(0);
});

it('shows the register of a finished batch with the marks it holds', function () {
    $held = liveClass();
    $this->enrolment->update(['status' => 'completed']);

    $dropped = User::factory()->student()->create(['name' => 'Dropped and marked']);
    $droppedUnmarked = User::factory()->student()->create(['name' => 'Dropped, never marked']);
    $moved = User::factory()->student()->create(['name' => 'Moved elsewhere']);

    foreach ([$dropped, $droppedUnmarked] as $user) {
        Enrollment::query()->create(['user_id' => $user->id, 'course_id' => $this->course->id, 'batch_id' => $this->batch->id, 'status' => 'dropped', 'enrolled_at' => now()->subWeeks(2)]);
    }

    foreach ([$this->student, $dropped, $moved] as $user) {
        Attendance::query()->create(['live_session_id' => $held->id, 'user_id' => $user->id, 'status' => 'present']);
    }

    $rows = collect($this->actingAs($this->owner)
        ->get("/admin/batches/{$this->batch->id}/sessions/{$held->id}/register")
        ->assertOk()
        ->viewData('page')['props']['rows'])->keyBy('userId');

    expect($rows->keys()->sort()->values()->all())->toBe(collect([$this->student->id, $dropped->id, $moved->id])->sort()->values()->all())
        ->and($rows[$this->student->id]['enrolment'])->toBe('completed')
        ->and($rows[$dropped->id]['enrolment'])->toBe('dropped')
        ->and($rows[$moved->id]['enrolment'])->toBeNull()
        ->and($rows->pluck('status')->unique()->values()->all())->toBe(['present']);
});

it('reads progress on a course without lessons from the classes the student attended', function () {
    $classes = collect(range(1, 4))->map(fn (int $day) => liveClass($day));
    $register = fn (LiveSession $class, string $status) => $this->actingAs($this->owner)
        ->post("/admin/batches/{$this->batch->id}/sessions/{$class->id}/register", [
            'marks' => [['user_id' => $this->student->id, 'status' => $status]],
        ]);

    $register($classes[0], 'absent');
    expect($this->enrolment->fresh()->progress_percent)->toBe(0);

    // Their own classes, out of the batch's: present and late count, excused does not.
    $register($classes[0], 'present');
    $register($classes[1], 'late');
    $register($classes[2], 'excused');
    expect($this->enrolment->fresh()->progress_percent)->toBe(50);

    $classes[2]->update(['status' => 'cancelled']);
    expect($this->enrolment->fresh()->progress_percent)->toBe(67);

    $classes[3]->delete();
    expect($this->enrolment->fresh()->progress_percent)->toBe(100);

    // Somebody joining late has attended nothing yet.
    $late = Enrollment::query()->create([
        'user_id' => User::factory()->student()->create()->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'enrolled_at' => now(),
    ]);
    expect($late->fresh()->progress_percent)->toBe(0);
});

it('leaves a dropped or paused student where they stopped', function () {
    liveClass(2, ['status' => 'held']);
    $this->enrolment->refresh()->update(['status' => 'dropped', 'progress_percent' => 0]);
    $paused = Enrollment::query()->create([
        'user_id' => User::factory()->student()->create()->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'enrolled_at' => now(),
        'status' => 'on_hold',
    ]);

    liveClass(1, ['status' => 'held']);

    expect($this->enrolment->fresh()->progress_percent)->toBe(0)
        ->and($paused->fresh()->progress_percent)->toBe(0);
});

it('never takes a finished enrolment back down, and leaves a course with lessons to its lessons', function () {
    $this->enrolment->update(['status' => 'completed', 'progress_percent' => 100]);
    liveClass(1, ['status' => 'held']);
    liveClass(-7);

    expect($this->enrolment->fresh()->progress_percent)->toBe(100);

    $module = $this->course->modules()->create(['title' => 'Week one', 'is_published' => true]);
    $module->lessons()->create(['course_id' => $this->course->id, 'title' => 'Hello', 'slug' => 'hello-'.uniqid(), 'type' => 'text', 'is_published' => true]);
    $this->enrolment->update(['status' => 'active', 'progress_percent' => 10]);
    liveClass(2, ['status' => 'held']);

    expect($this->enrolment->fresh()->progress_percent)->toBe(10);
});

it('holds a certificate back when attendance is below the course minimum', function () {
    $held = collect(range(1, 4))->map(fn (int $day) => liveClass($day));

    Attendance::query()->create([
        'live_session_id' => $held->first()->id,
        'user_id' => $this->student->id,
        'status' => 'present',
    ]);

    $result = app(ResultCard::class)->for($this->enrolment->fresh());

    expect($result['attendance']['percent'])->toBe(25.0)
        ->and($result['shortOnAttendance'])->toBeTrue()
        ->and($result['passing'])->toBeFalse();
});

it('leaves a cancelled class out of the count', function () {
    liveClass(2);
    liveClass(1, ['status' => 'cancelled']);

    $result = app(ResultCard::class)->for($this->enrolment->fresh());

    expect($result['attendance']['held'])->toBe(1);
});

/* ------------------------------------------------------------- the join */

it('opens the join link fifteen minutes before a class and not before', function () {
    $soon = liveClass(0, ['scheduled_at' => now()->addMinutes(10), 'meet_link' => 'https://meet.google.com/x']);
    $later = liveClass(0, ['scheduled_at' => now()->addHours(3), 'meet_link' => 'https://meet.google.com/y']);

    expect($soon->isJoinable())->toBeTrue()
        ->and($later->isJoinable())->toBeFalse();

    // Refused rather than quietly redirected: the student already knows the
    // class exists, so the honest answer is that it has not opened yet.
    $this->actingAs($this->student)
        ->get("/student/classes/{$later->id}/join")
        ->assertForbidden();

    $this->actingAs($this->student)
        ->get("/student/classes/{$soon->id}/join")
        ->assertRedirect($soon->link());
});
