<?php

use App\Enums\Role;
use App\Models\Announcement;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Permission;
use App\Models\Quiz;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    $this->owner = User::factory()->owner()->create();

    $this->course = Course::query()->create([
        'title' => 'Web development internship',
        'slug' => 'admin-lms-course-'.uniqid(),
        'type' => 'internship',
        'tagline' => 'Build something real',
        'summary' => 'Eight weeks, live.',
    ]);

    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id,
        'name' => 'September batch',
        'code' => 'AD-'.uniqid(),
        'starts_on' => today()->subWeek(),
    ]);
});

it('opens every admin screen in the module', function () {
    $screens = [
        "/admin/courses/{$this->course->id}/builder" => 'admin/courses/Builder',
        "/admin/courses/{$this->course->id}/quizzes" => 'admin/assessment/Quizzes',
        "/admin/courses/{$this->course->id}/assignments" => 'admin/assessment/Assignments',
        "/admin/batches/{$this->batch->id}/run" => 'admin/batches/Run',
        "/admin/batches/{$this->batch->id}/warnings" => 'admin/batches/Warnings',
        "/admin/batches/{$this->batch->id}/reviews" => 'admin/batches/Reviews',
        "/admin/batches/{$this->batch->id}/credentials" => 'admin/batches/Credentials',
        '/admin/marking' => 'admin/assessment/Marking',
    ];

    foreach ($screens as $path => $component) {
        $this->actingAs($this->owner)
            ->get($path)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component($component));
    }
});

/* ---------------------------------------------------------- the builder */

it('builds a module and a lesson', function () {
    $this->actingAs($this->owner)
        ->post("/admin/courses/{$this->course->id}/modules", ['title' => 'Week one'])
        ->assertRedirect();

    $module = CourseModule::query()->sole();

    $this->actingAs($this->owner)
        ->post("/admin/courses/{$this->course->id}/modules/{$module->id}/lessons", [
            'title' => 'Request to response',
            'duration_minutes' => 40,
        ])->assertRedirect();

    expect(Lesson::query()->sole()->slug)->toStartWith('request-to-response');
});

it('will not let a lesson wait for itself', function () {
    $module = CourseModule::query()->create(['course_id' => $this->course->id, 'title' => 'Week one']);

    $lesson = Lesson::query()->create([
        'course_id' => $this->course->id,
        'course_module_id' => $module->id,
        'title' => 'Only lesson',
        'slug' => 'only-lesson',
    ]);

    $this->actingAs($this->owner)->put("/admin/courses/{$this->course->id}/lessons/{$lesson->id}", [
        'title' => 'Only lesson',
        'prerequisite_lesson_id' => $lesson->id,
    ])->assertRedirect();

    // A lock nobody can ever open is not a lock, it is a mistake.
    expect($lesson->fresh()->prerequisite_lesson_id)->toBeNull();
});

it('reorders modules', function () {
    $first = CourseModule::query()->create(['course_id' => $this->course->id, 'title' => 'One', 'sort_order' => 0]);
    $second = CourseModule::query()->create(['course_id' => $this->course->id, 'title' => 'Two', 'sort_order' => 1]);

    $this->actingAs($this->owner)
        ->post("/admin/courses/{$this->course->id}/modules/reorder", ['order' => [$second->id, $first->id]])
        ->assertRedirect();

    expect($second->fresh()->sort_order)->toBe(0)
        ->and($first->fresh()->sort_order)->toBe(1);
});

it('refuses to delete a quiz somebody has already sat', function () {
    $quiz = Quiz::query()->create([
        'course_id' => $this->course->id,
        'title' => 'Week one check',
        'is_published' => true,
    ]);

    $student = User::factory()->student()->create();

    $quiz->attempts()->create([
        'user_id' => $student->id,
        'attempt_number' => 1,
        'started_at' => now()->subHour(),
        'submitted_at' => now()->subMinutes(50),
    ]);

    $this->actingAs($this->owner)
        ->delete("/admin/courses/{$this->course->id}/quizzes/{$quiz->id}")
        ->assertSessionHasErrors('quiz');

    expect(Quiz::query()->count())->toBe(1);
});

/* ------------------------------------------------------------ the batch */

it('cancels a class that has a register rather than deleting it', function () {
    $session = $this->batch->sessions()->create([
        'title' => 'Session one',
        'scheduled_at' => now()->subDay(),
    ]);

    $student = User::factory()->student()->create();

    Enrollment::query()->create([
        'user_id' => $student->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'enrolled_at' => now()->subWeek(),
        'has_paid' => true,
    ]);

    $session->attendances()->create(['user_id' => $student->id, 'status' => 'present']);

    $this->actingAs($this->owner)
        ->delete("/admin/batches/{$this->batch->id}/sessions/{$session->id}")
        ->assertRedirect();

    // An attendance record with no class attached is worse than a cancelled one.
    expect($session->fresh()->status)->toBe('cancelled');
});

it('sends a course wide announcement to every batch of the course', function () {
    $this->actingAs($this->owner)->post("/admin/batches/{$this->batch->id}/announcements", [
        'title' => 'Project briefs are up',
        'body' => 'Start this week.',
        'course_wide' => true,
    ])->assertRedirect();

    $announcement = Announcement::query()->sole();

    expect($announcement->batch_id)->toBeNull()
        ->and($announcement->course_id)->toBe($this->course->id);
});

/* --------------------------------------------------------- permissions */

it('keeps a trainer out of the course pricing', function () {
    $trainer = User::factory()->create(['role' => Role::Admin]);
    $trainer->permissions()->sync(Permission::query()->where('key', 'students.view')->pluck('id'));

    // Running a batch: allowed.
    $this->actingAs($trainer)
        ->get("/admin/batches/{$this->batch->id}/run")
        ->assertOk();

    // Editing what the course costs: not.
    $this->actingAs($trainer)
        ->get("/admin/courses/{$this->course->id}/builder")
        ->assertForbidden();
});

it('does not let a trainer issue a certificate without the permission for it', function () {
    $trainer = User::factory()->create(['role' => Role::Admin]);
    $trainer->permissions()->sync(Permission::query()->where('key', 'students.view')->pluck('id'));

    $student = User::factory()->student()->create();

    $enrolment = Enrollment::query()->create([
        'user_id' => $student->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'enrolled_at' => now()->subWeeks(8),
        'has_paid' => true,
    ]);

    $this->actingAs($trainer)
        ->post("/admin/enrolments/{$enrolment->id}/certificate", ['force' => true])
        ->assertForbidden();
});
