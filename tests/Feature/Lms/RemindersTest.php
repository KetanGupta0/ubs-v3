<?php

use App\Models\Assignment;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LiveSession;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\StudentWarning;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\LearningReminder;
use App\Services\Lms\Reminders;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->trainer = User::factory()->admin()->create(['name' => 'Meera Trainer']);

    $this->course = Course::query()->create(['title' => 'Python basics', 'slug' => 'rem-'.uniqid(), 'type' => 'course', 'tagline' => 'x', 'summary' => 'x']);
    $this->batch = Batch::query()->create(['course_id' => $this->course->id, 'name' => 'Morning batch', 'code' => 'RM-'.uniqid(), 'trainer_id' => $this->trainer->id]);

    $this->enrol = function (string $name, string $status = 'active') {
        $student = User::factory()->student()->create(['name' => $name]);
        Enrollment::query()->create([
            'user_id' => $student->id, 'course_id' => $this->course->id, 'batch_id' => $this->batch->id,
            'enrolled_at' => now(), 'has_paid' => true, 'status' => $status,
        ]);

        return $student;
    };

    $this->asha = ($this->enrol)('Asha Verma');
    $this->ravi = ($this->enrol)('Ravi Kumar');
    $this->gone = ($this->enrol)('Gone Student', 'dropped');

    $this->session = fn (string $title, $at, string $status = 'scheduled') => LiveSession::query()->create([
        'batch_id' => $this->batch->id, 'title' => $title, 'scheduled_at' => $at, 'duration_minutes' => 60, 'status' => $status,
    ]);
});

it('reminds a batch the day before a class and an hour before, once each', function () {
    $tomorrow = ($this->session)('Loops, live', now()->addHours(20));
    $soon = ($this->session)('Functions, live', now()->addMinutes(45));
    ($this->session)('In between', now()->addMinutes(90));
    ($this->session)('Cancelled', now()->addHours(5), 'cancelled');

    $first = app(Reminders::class)->run();
    $again = app(Reminders::class)->run();

    expect($first)->toMatchArray(['class_day' => 2, 'class_hour' => 2])
        ->and($again)->toMatchArray(['class_day' => 0, 'class_hour' => 0, 'deadline' => 0]);

    Notification::assertSentToTimes($this->asha, LearningReminder::class, 2);
    Notification::assertSentTo($this->asha, LearningReminder::class, fn ($n) => $n->kind === 'class_day' && str_contains($n->subject, 'Loops, live'));
    Notification::assertSentTo($this->asha, LearningReminder::class, fn ($n) => $n->kind === 'class_hour' && str_contains($n->subject, 'Functions, live'));
    Notification::assertNotSentTo($this->gone, LearningReminder::class);
});

it('leaves out a kind of reminder a student switched off', function () {
    ($this->session)('Loops, live', now()->addHours(20));

    $this->actingAs($this->asha)->put('/student/preferences', ['on' => ['class_hour', 'deadline', 'missed']])->assertSessionHas('success');
    expect($this->asha->fresh()->reminder_preferences)->toBe(['off' => ['class_day']]);

    app(Reminders::class)->run();

    Notification::assertNotSentTo($this->asha, LearningReminder::class);
    Notification::assertSentTo($this->ravi, LearningReminder::class);

    $this->actingAs($this->asha)->get('/student/preferences')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('student/Preferences')->where('reminders.0.kind', 'class_day')->where('reminders.0.on', false));

    // Switching everything back on stores nothing.
    $this->actingAs($this->asha)->put('/student/preferences', ['on' => array_keys(Reminders::KINDS)]);
    expect($this->asha->fresh()->reminder_preferences)->toBeNull();
});

it('reminds whoever has not handed work in, a day before it closes', function () {
    $assignment = Assignment::query()->create([
        'course_id' => $this->course->id, 'title' => 'First screen', 'brief' => 'x', 'due_at' => now()->addHours(10), 'max_marks' => 10, 'is_published' => true,
    ]);
    Submission::query()->create(['assignment_id' => $assignment->id, 'user_id' => $this->ravi->id, 'submitted_at' => now()]);
    Assignment::query()->create([
        'course_id' => $this->course->id, 'title' => 'Far off', 'brief' => 'x', 'due_at' => now()->addDays(5), 'max_marks' => 10, 'is_published' => true,
    ]);

    $quiz = Quiz::query()->create(['course_id' => $this->course->id, 'title' => 'Loops quiz', 'closes_at' => now()->addHours(5), 'is_published' => true]);
    QuizAttempt::query()->create(['quiz_id' => $quiz->id, 'user_id' => $this->asha->id, 'started_at' => now()->subHour(), 'submitted_at' => now()->subMinutes(50)]);

    expect(app(Reminders::class)->deadlines())->toBe(2);

    Notification::assertSentTo($this->asha, LearningReminder::class, fn ($n) => $n->kind === 'deadline' && str_contains($n->subject, 'First screen'));
    Notification::assertNotSentTo($this->asha, LearningReminder::class, fn ($n) => str_contains($n->subject, 'Loops quiz'));
    Notification::assertSentTo($this->ravi, LearningReminder::class, fn ($n) => str_contains($n->subject, 'Loops quiz') && $n->path === "/student/quizzes/{$quiz->id}");
    Notification::assertNotSentTo($this->ravi, LearningReminder::class, fn ($n) => str_contains($n->subject, 'First screen'));
});

it('tells a student and their trainer after two missed classes in a row, without issuing a warning', function () {
    $first = ($this->session)('Loops, live', now()->subWeek());
    $second = ($this->session)('Functions, live', now()->subDay());
    $path = fn (LiveSession $session) => "/admin/batches/{$this->batch->getRouteKey()}/sessions/{$session->id}/register";

    $this->actingAs($this->owner)->post($path($first), ['marks' => [
        ['user_id' => $this->asha->id, 'status' => 'absent'],
        ['user_id' => $this->ravi->id, 'status' => 'absent'],
    ]])->assertSessionHas('success');
    Notification::assertNothingSent();

    $this->ravi->forceFill(['reminder_preferences' => ['off' => ['missed']]])->save();

    $this->actingAs($this->owner)->post($path($second), ['marks' => [
        ['user_id' => $this->asha->id, 'status' => 'absent'],
        ['user_id' => $this->ravi->id, 'status' => 'absent'],
    ]]);
    // Marking it again sends nothing new.
    $this->actingAs($this->owner)->post($path($second), ['marks' => [['user_id' => $this->asha->id, 'status' => 'absent']]]);

    Notification::assertSentToTimes($this->asha, LearningReminder::class, 1);
    Notification::assertNotSentTo($this->ravi, LearningReminder::class);
    Notification::assertSentToTimes($this->trainer, LearningReminder::class, 2);
    expect(StudentWarning::query()->count())->toBe(0);
});

it('runs from the scheduler', function () {
    ($this->session)('Loops, live', now()->addMinutes(30));

    $this->artisan('lms:remind')->expectsOutputToContain('2 for classes within the hour')->assertSuccessful();
});
