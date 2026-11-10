<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Permission;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptGrant;
use App\Models\User;
use App\Services\Lms\QuizGrader;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->student = User::factory()->student()->create(['name' => 'Imran Qureshi']);

    $course = Course::query()->create([
        'title' => 'SQL mastery', 'slug' => 'grant-course-'.uniqid(), 'type' => 'course',
        'tagline' => 'x', 'summary' => 'y', 'is_published' => true,
    ]);
    $batch = Batch::query()->create(['course_id' => $course->id, 'name' => 'SQL November', 'code' => 'GR-'.uniqid(), 'starts_on' => today()]);
    Enrollment::query()->create([
        'user_id' => $this->student->id, 'course_id' => $course->id, 'batch_id' => $batch->id,
        'enrolled_at' => now(), 'has_paid' => true,
    ]);

    $this->quiz = Quiz::query()->create([
        'course_id' => $course->id, 'title' => 'Joins check', 'attempts_allowed' => 1,
        'pass_percent' => 60, 'shuffle_questions' => false, 'is_published' => true,
    ]);
    Question::query()->create([
        'quiz_id' => $this->quiz->id, 'type' => 'mcq', 'body' => 'Which join keeps unmatched left rows?',
        'options' => ['Inner', 'Left'], 'correct' => ['Left'], 'marks' => 1,
    ]);

    // The one attempt they were allowed, used up.
    $this->attempt = app(QuizGrader::class)->start($this->quiz, $this->student, $batch->id);
    app(QuizGrader::class)->submit($this->attempt, [$this->quiz->questions()->value('id') => 'Inner']);
});

it('stops a student who has used every attempt', function () {
    $this->actingAs($this->student)
        ->post("/student/quizzes/{$this->quiz->id}/start")
        ->assertSessionHasErrors('quiz');

    expect(QuizAttempt::query()->count())->toBe(1);
});

it('lets staff allow one student more attempts, with a reason', function () {
    $this->actingAs($this->owner)
        ->post("/admin/marking/attempts/{$this->attempt->id}/grant", [
            'extra_attempts' => 1,
            'reason' => 'Power cut during the first attempt',
        ])
        ->assertSessionHas('success', 'Imran Qureshi can now make 1 more attempt.');

    expect($this->quiz->attemptsAllowedFor($this->student))->toBe(2)
        ->and(QuizAttemptGrant::query()->sole()->granted_by)->toBe($this->owner->id)
        ->and(AuditLog::query()->where('action', 'quiz.attempts_granted')->sole()->changes['reason'])
        ->toBe('Power cut during the first attempt');

    $this->actingAs($this->student)
        ->post("/student/quizzes/{$this->quiz->id}/start")
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $second = QuizAttempt::query()->latest('id')->first();

    // Their first attempt stands; the new one is numbered after it.
    expect(QuizAttempt::query()->count())->toBe(2)
        ->and($second->attempt_number)->toBe(2);
});

it('gives the extra attempt to that student only', function () {
    $other = User::factory()->student()->create();
    Enrollment::query()->create([
        'user_id' => $other->id, 'course_id' => $this->quiz->course_id,
        'enrolled_at' => now(), 'has_paid' => true,
    ]);

    $this->actingAs($this->owner)->post("/admin/marking/attempts/{$this->attempt->id}/grant", [
        'extra_attempts' => 2, 'reason' => 'Medical absence on the day',
    ]);

    expect($this->quiz->attemptsAllowedFor($other))->toBe(1)
        ->and($this->quiz->attemptsAllowedFor($this->student))->toBe(3);
});

it('adds grants up, and shows them on the attempt', function () {
    foreach (['Power cut', 'Resit agreed after review'] as $reason) {
        $this->actingAs($this->owner)->post("/admin/marking/attempts/{$this->attempt->id}/grant", [
            'extra_attempts' => 1, 'reason' => $reason.' — noted',
        ]);
    }

    $this->actingAs($this->owner)
        ->get("/admin/marking/attempts/{$this->attempt->id}")
        ->assertInertia(fn ($page) => $page
            ->where('attempt.attemptsUsed', 1)
            ->where('attempt.attemptsAllowed', 3)
            ->has('grants', 2)
            ->where('canGrant', true));
});

it('needs a reason, and the update permission', function () {
    $this->actingAs($this->owner)
        ->post("/admin/marking/attempts/{$this->attempt->id}/grant", ['extra_attempts' => 1])
        ->assertSessionHasErrors('reason');

    $marker = User::factory()->admin()->create();
    $marker->permissions()->attach(Permission::query()->where('key', 'students.view')->sole());

    $this->actingAs($marker->fresh())
        ->post("/admin/marking/attempts/{$this->attempt->id}/grant", ['extra_attempts' => 1, 'reason' => 'Because I said so'])
        ->assertForbidden();

    expect(QuizAttemptGrant::query()->count())->toBe(0);
});
