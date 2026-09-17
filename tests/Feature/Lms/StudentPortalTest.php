<?php

use App\Models\Assignment;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->student = User::factory()->student()->create();
    $this->stranger = User::factory()->student()->create();

    $this->course = Course::query()->create([
        'title' => 'Web development internship',
        'slug' => 'portal-course-'.uniqid(),
        'type' => 'internship',
        'tagline' => 'Build something real',
        'summary' => 'Eight weeks, live.',
    ]);

    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id,
        'name' => 'September batch',
        'code' => 'SP-'.uniqid(),
        'starts_on' => today()->subWeek(),
    ]);

    $this->enrolment = Enrollment::query()->create([
        'user_id' => $this->student->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'enrolled_at' => now()->subWeek(),
        'has_paid' => true,
    ]);

    $module = CourseModule::query()->create(['course_id' => $this->course->id, 'title' => 'Week one']);

    $this->lesson = Lesson::query()->create([
        'course_id' => $this->course->id,
        'course_module_id' => $module->id,
        'title' => 'Request to response',
        'slug' => 'request-to-response',
    ]);

    $this->assignment = Assignment::query()->create([
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'title' => 'First screen',
        'brief' => 'Build the first screen end to end.',
        'due_at' => now()->addDays(3),
        'max_marks' => 50,
        'is_published' => true,
    ]);
});

/* ------------------------------------------------------------- the pages */

it('opens every student screen', function (string $path, string $component) {
    $this->actingAs($this->student)
        ->get($path)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    ['/student', 'student/Dashboard'],
    ['/student/courses', 'student/courses/Index'],
    ['/student/classes', 'student/classes/Index'],
    ['/student/attendance', 'student/classes/Attendance'],
    ['/student/assignments', 'student/assignments/Index'],
    ['/student/results', 'student/Results'],
    ['/student/leaderboard', 'student/Leaderboard'],
    ['/student/certificates', 'student/Certificates'],
    ['/student/announcements', 'student/Announcements'],
    ['/student/warnings', 'student/Warnings'],
    ['/student/catalogue', 'student/Catalogue'],
    ['/student/payments', 'student/payments/Index'],
]);

it('sends a client who lands on the student area to their own dashboard', function () {
    // A stale bookmark in practice, rather than an attack, so it is a redirect
    // rather than a slammed door.
    $this->actingAs(User::factory()->client()->create())
        ->get('/student')
        ->assertRedirect('/client');
});

/* ------------------------------------------------------------ submitting */

it('records a submission with its files', function () {
    Storage::fake('private');

    $this->actingAs($this->student)->post("/student/assignments/{$this->assignment->id}", [
        'notes' => 'The form validates on the server.',
        'repository_url' => 'https://github.com/example/project',
        'files' => [UploadedFile::fake()->create('report.pdf', 40)],
    ])->assertRedirect();

    $submission = Submission::query()->sole();

    expect($submission->user_id)->toBe($this->student->id)
        ->and($submission->submitted_at)->not->toBeNull()
        ->and($submission->is_late)->toBeFalse()
        ->and($submission->files)->toHaveCount(1);

    Storage::disk('private')->assertExists($submission->files[0]['path']);
});

it('flags a submission handed in after the deadline', function () {
    $this->assignment->forceFill(['due_at' => now()->subDay()])->save();

    $this->actingAs($this->student)
        ->post("/student/assignments/{$this->assignment->id}", ['notes' => 'Late, sorry.']);

    expect(Submission::query()->sole()->is_late)->toBeTrue();
});

it('clears an existing mark when work is submitted again', function () {
    Submission::query()->create([
        'assignment_id' => $this->assignment->id,
        'user_id' => $this->student->id,
        'submitted_at' => now()->subDays(2),
        'status' => 'evaluated',
        'marks' => 30,
        'feedback' => 'Good, but the validation is client side only.',
    ]);

    $this->actingAs($this->student)
        ->post("/student/assignments/{$this->assignment->id}", ['notes' => 'Fixed the validation.']);

    // A mark attached to work that has since changed is worse than no mark.
    expect(Submission::query()->sole()->marks)->toBeNull();
});

it('does not accept work from somebody not on the course', function () {
    $this->actingAs($this->stranger)
        ->post("/student/assignments/{$this->assignment->id}", ['notes' => 'Hello.'])
        ->assertNotFound();

    expect(Submission::query()->count())->toBe(0);
});

/* -------------------------------------------------------------- progress */

it('counts progress from the lessons actually finished', function () {
    expect($this->enrolment->fresh()->progress_percent)->toBe(0);

    $this->actingAs($this->student)
        ->post("/student/courses/{$this->course->id}/lessons/{$this->lesson->id}/complete")
        ->assertRedirect();

    expect($this->enrolment->fresh()->progress_percent)->toBe(100);
});

it('does not leak another student’s enrolment into the dashboard', function () {
    $props = $this->actingAs($this->stranger)->get('/student')->inertiaProps();

    expect(json_encode($props))->not->toContain($this->course->title);
});
