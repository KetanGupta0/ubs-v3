<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Lms\QuizGrader;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->student = User::factory()->student()->create();

    $this->course = Course::query()->create([
        'title' => 'Web development internship',
        'slug' => 'quiz-course-'.uniqid(),
        'type' => 'internship',
        'tagline' => 'Build something real',
        'summary' => 'Eight weeks, live.',
    ]);

    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id,
        'name' => 'September batch',
        'code' => 'QZ-'.uniqid(),
        'starts_on' => today()->subWeek(),
    ]);

    Enrollment::query()->create([
        'user_id' => $this->student->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'enrolled_at' => now()->subWeek(),
        'has_paid' => true,
    ]);

    $this->quiz = Quiz::query()->create([
        'course_id' => $this->course->id,
        'title' => 'Week one check',
        'time_limit_minutes' => 10,
        'attempts_allowed' => 2,
        'pass_percent' => 60,
        'shuffle_questions' => false,
        'is_published' => true,
    ]);

    $this->choice = Question::query()->create([
        'quiz_id' => $this->quiz->id,
        'type' => 'mcq',
        'body' => 'Where should a form be validated?',
        'options' => ['In the browser only', 'On the server'],
        'correct' => ['On the server'],
        'marks' => 6,
    ]);

    $this->written = Question::query()->create([
        'quiz_id' => $this->quiz->id,
        'type' => 'short',
        'body' => 'Why does a foreign key exist?',
        'marks' => 4,
    ]);
});

/* -------------------------------------------------------------- the clock */

it('sets the deadline on the server when an attempt starts', function () {
    $attempt = app(QuizGrader::class)->start($this->quiz, $this->student);

    expect($attempt->expires_at->diffInMinutes($attempt->started_at, absolute: true))
        ->toEqualWithDelta(10, 0.1);
});

it('marks only what was answered before the deadline', function () {
    $attempt = app(QuizGrader::class)->start($this->quiz, $this->student);

    // The browser sends answers well after the time ran out, which is exactly
    // the case a timer living in the page cannot defend against.
    $this->travel(15)->minutes();

    app(QuizGrader::class)->submit($attempt, [$this->choice->id => ['On the server']]);

    expect($attempt->fresh()->score)->toBe(0)
        ->and($attempt->fresh()->submitted_at)->not->toBeNull();
});

it('still marks a submission that is only seconds late', function () {
    $attempt = app(QuizGrader::class)->start($this->quiz, $this->student);

    $this->travel(10)->minutes();
    $this->travel(20)->seconds();

    app(QuizGrader::class)->submit($attempt, [$this->choice->id => ['On the server']]);

    expect($attempt->fresh()->score)->toBe(6);
});

it('closes an abandoned attempt rather than leaving it open forever', function () {
    $attempt = app(QuizGrader::class)->start($this->quiz, $this->student);

    $this->travel(20)->minutes();

    app(QuizGrader::class)->closeExpired();

    expect($attempt->fresh()->submitted_at)->not->toBeNull();
});

/* ------------------------------------------------------------- the answers */

it('never sends the answer key while an attempt is open', function () {
    $this->actingAs($this->student)->post("/student/quizzes/{$this->quiz->id}/start");

    $attempt = QuizAttempt::query()->sole();

    $this->actingAs($this->student)
        ->get("/student/quizzes/{$this->quiz->id}/attempts/{$attempt->id}")
        ->assertInertia(fn ($page) => $page
            ->component('student/quizzes/Attempt')
            ->has('questions.0.options')
            ->missing('questions.0.correct'));
});

it('marks a choice question itself and leaves a written one for a person', function () {
    $attempt = app(QuizGrader::class)->start($this->quiz, $this->student);

    app(QuizGrader::class)->submit($attempt, [
        $this->choice->id => ['On the server'],
        $this->written->id => 'So a row cannot point at a record that is not there.',
    ]);

    $attempt = $attempt->fresh();

    expect($attempt->score)->toBe(6)
        ->and($attempt->needs_review)->toBeTrue()
        // Not a fail: the written answer has not been read yet, and showing a
        // student a fail they have not earned is worse than showing nothing.
        ->and($attempt->passed)->toBeFalse();
});

it('retotals the attempt once the written answer is marked', function () {
    $attempt = app(QuizGrader::class)->start($this->quiz, $this->student);

    app(QuizGrader::class)->submit($attempt, [
        $this->choice->id => ['On the server'],
        $this->written->id => 'Referential integrity.',
    ]);

    $answer = $attempt->answers()->where('question_id', $this->written->id)->sole();

    $this->actingAs($this->owner)
        ->post("/admin/marking/attempts/{$attempt->id}/answers/{$answer->id}", [
            'marks' => 4,
            'feedback' => 'Right, and said in one line.',
        ])->assertRedirect();

    $attempt = $attempt->fresh();

    expect($attempt->score)->toBe(10)
        ->and((float) $attempt->percent)->toBe(100.0)
        ->and($attempt->passed)->toBeTrue()
        ->and($attempt->needs_review)->toBeFalse();
});

it('refuses a third attempt at a quiz that allows two', function () {
    foreach (range(1, 2) as $ignored) {
        $attempt = app(QuizGrader::class)->start($this->quiz, $this->student);
        app(QuizGrader::class)->submit($attempt, [$this->choice->id => ['In the browser only']]);
    }

    expect(fn () => app(QuizGrader::class)->start($this->quiz, $this->student))
        ->toThrow(RuntimeException::class);
});

it('does not open a quiz on a course this student is not on', function () {
    $other = User::factory()->student()->create();

    $this->actingAs($other)
        ->get("/student/quizzes/{$this->quiz->id}")
        ->assertNotFound();
});
