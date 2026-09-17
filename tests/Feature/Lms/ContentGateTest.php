<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Lms\ContentGate;

beforeEach(function () {
    $this->student = User::factory()->student()->create();

    $this->course = Course::query()->create([
        'title' => 'Web development internship',
        'slug' => 'web-development-internship-'.uniqid(),
        'type' => 'internship',
        'tagline' => 'Build something real',
        'summary' => 'Eight weeks, live.',
        'price' => 1500000,
    ]);

    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id,
        'name' => 'September batch',
        'code' => 'WEB-'.uniqid(),
        'starts_on' => today()->subDays(3),
        'ends_on' => today()->addWeeks(6),
    ]);

    $this->module = CourseModule::query()->create([
        'course_id' => $this->course->id,
        'title' => 'Week one',
    ]);

    $this->enrolment = Enrollment::query()->create([
        'user_id' => $this->student->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'enrolled_at' => now()->subDays(3),
        'has_paid' => true,
    ]);
});

function lesson(array $attributes = []): Lesson
{
    return Lesson::query()->create([
        'course_id' => test()->course->id,
        'course_module_id' => test()->module->id,
        'title' => $attributes['title'] ?? 'A lesson',
        'slug' => 'lesson-'.uniqid(),
        ...$attributes,
    ]);
}

function gate(): ContentGate
{
    return ContentGate::for(test()->student->fresh(), test()->enrolment->fresh());
}

/* ------------------------------------------------------------------ drip */

it('keeps a lesson shut until its drip day arrives', function () {
    $later = lesson(['unlock_after_days' => 14]);

    expect(gate()->check($later)->open)->toBeFalse()
        ->and(gate()->check($later)->kind)->toBe('schedule');
});

it('opens a lesson once the drip day has passed', function () {
    expect(gate()->check(lesson(['unlock_after_days' => 1]))->open)->toBeTrue();
});

it('counts drip days from the batch start, so a cohort moves together', function () {
    // Enrolled today, but the batch began three days ago: a three day drip is
    // open, because the whole batch is on the same lesson.
    $this->enrolment->forceFill(['enrolled_at' => now()])->save();

    expect(gate()->check(lesson(['unlock_after_days' => 3]))->open)->toBeTrue();
});

it('lets a fixed date beat a relative one', function () {
    $fixed = lesson(['unlock_at' => now()->addWeek()]);

    expect(gate()->check($fixed)->open)->toBeFalse();
});

/* ---------------------------------------------------------- prerequisite */

it('waits for the lesson before it to be finished', function () {
    $first = lesson(['title' => 'First']);
    $second = lesson(['title' => 'Second', 'prerequisite_lesson_id' => $first->id]);

    expect(gate()->check($second)->open)->toBeFalse()
        ->and(gate()->check($second)->kind)->toBe('prerequisite');

    $first->completions()->create(['user_id' => $this->student->id, 'completed_at' => now()]);

    expect(gate()->check($second->fresh())->open)->toBeTrue();
});

/* --------------------------------------------------------------- payment */

it('seals a paid lesson until the fee is settled', function () {
    $this->enrolment->forceFill(['has_paid' => false])->save();

    $paid = lesson(['requires_payment' => true]);

    expect(gate()->check($paid)->open)->toBeFalse()
        ->and(gate()->check($paid)->kind)->toBe('payment');
});

it('opens a preview lesson even when the fee is outstanding', function () {
    $this->enrolment->forceFill(['has_paid' => false])->save();

    expect(gate()->check(lesson(['requires_payment' => true, 'is_preview' => true]))->open)->toBeTrue();
});

/* ------------------------------------------------------------ quiz score */

it('holds a lesson behind a quiz score', function () {
    $quiz = Quiz::query()->create([
        'course_id' => $this->course->id,
        'title' => 'Week one check',
        'pass_percent' => 60,
        'is_published' => true,
    ]);

    $gated = lesson(['required_quiz_id' => $quiz->id, 'min_quiz_score' => 60]);

    expect(gate()->check($gated)->open)->toBeFalse();

    QuizAttempt::query()->create([
        'quiz_id' => $quiz->id,
        'user_id' => $this->student->id,
        'attempt_number' => 1,
        'started_at' => now()->subHour(),
        'submitted_at' => now()->subMinutes(50),
        'score' => 4,
        'total_marks' => 10,
        'percent' => 40,
    ]);

    expect(gate()->check($gated)->open)->toBeFalse('forty percent is not sixty');

    QuizAttempt::query()->create([
        'quiz_id' => $quiz->id,
        'user_id' => $this->student->id,
        'attempt_number' => 2,
        'started_at' => now()->subMinutes(30),
        'submitted_at' => now()->subMinutes(20),
        'score' => 7,
        'total_marks' => 10,
        'percent' => 70,
    ]);

    expect(gate()->check($gated)->open)->toBeTrue();
});

/* -------------------------------------------------------- what is served */

it('renders the reason rather than hiding a locked lesson', function () {
    $locked = lesson(['unlock_after_days' => 30]);

    $this->actingAs($this->student)
        ->get("/student/courses/{$this->course->id}/lessons/{$locked->id}")
        ->assertInertia(fn ($page) => $page
            ->component('student/courses/Locked')
            ->where('lock.kind', 'schedule'));
});

it('refuses to mark a locked lesson as finished', function () {
    $locked = lesson(['unlock_after_days' => 30]);

    $this->actingAs($this->student)
        ->post("/student/courses/{$this->course->id}/lessons/{$locked->id}/complete")
        ->assertForbidden();

    expect($locked->completions()->count())->toBe(0);
});

it('does not find a course this student is not on', function () {
    $other = User::factory()->student()->create();

    $this->actingAs($other)
        ->get("/student/courses/{$this->course->id}")
        ->assertNotFound();
});
