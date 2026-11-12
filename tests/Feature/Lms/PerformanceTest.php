<?php

use App\Models\ActivityLog;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\Batch;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\LeaderboardPoint;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\LiveSession;
use App\Models\Permission;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Submission;
use App\Models\User;
use App\Services\Lms\Performance;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->owner = User::factory()->owner()->create();

    $this->course = Course::query()->create(['title' => 'Python basics', 'slug' => 'perf-'.uniqid(), 'type' => 'course', 'tagline' => 'x', 'summary' => 'x']);
    $this->batch = Batch::query()->create(['course_id' => $this->course->id, 'name' => 'Morning batch', 'code' => 'PF-'.uniqid()]);

    $this->enrol = function (string $name) {
        $student = User::factory()->student()->create(['name' => $name]);
        Enrollment::query()->create([
            'user_id' => $student->id, 'course_id' => $this->course->id, 'batch_id' => $this->batch->id,
            'enrolled_at' => now()->subMonth(), 'has_paid' => true,
        ]);

        return $student;
    };

    $this->steady = ($this->enrol)('Steady Student');
    $this->slipping = ($this->enrol)('Slipping Student');

    // Four classes held; Steady at all four, Slipping at one.
    foreach (range(1, 4) as $week) {
        $session = LiveSession::query()->create([
            'batch_id' => $this->batch->id, 'title' => "Week {$week}", 'scheduled_at' => now()->subWeeks(5 - $week),
            'duration_minutes' => 60, 'status' => 'held',
        ]);
        Attendance::query()->create(['live_session_id' => $session->id, 'user_id' => $this->steady->id, 'status' => 'present']);
        Attendance::query()->create(['live_session_id' => $session->id, 'user_id' => $this->slipping->id, 'status' => $week === 1 ? 'present' : 'absent']);
    }

    // Three pieces of work past their date: Steady hands in two on time and one late; Slipping none.
    foreach (range(1, 3) as $n) {
        $assignment = Assignment::query()->create([
            'course_id' => $this->course->id, 'title' => "Task {$n}", 'brief' => 'x', 'due_at' => now()->subDays(10 - $n), 'max_marks' => 10, 'is_published' => true,
        ]);
        Submission::query()->create([
            'assignment_id' => $assignment->id, 'user_id' => $this->steady->id,
            'submitted_at' => $n === 3 ? $assignment->due_at->copy()->addHour() : $assignment->due_at->copy()->subHour(),
        ]);
    }

    // Quizzes: Steady rising 50, 60, 80, 90; Slipping 40, 30.
    $quiz = Quiz::query()->create(['course_id' => $this->course->id, 'title' => 'Loops quiz', 'is_published' => true, 'max_attempts' => 5]);
    foreach ([[$this->steady, [50, 60, 80, 90]], [$this->slipping, [40, 30]]] as [$student, $scores]) {
        foreach ($scores as $i => $score) {
            QuizAttempt::query()->create([
                'quiz_id' => $quiz->id, 'user_id' => $student->id, 'attempt_number' => $i + 1,
                'started_at' => now()->subDays(8 - $i), 'submitted_at' => now()->subDays(8 - $i)->addMinutes(20), 'percent' => $score,
            ]);
        }
    }

    // Lessons: two published, Steady finished both.
    $module = CourseModule::query()->create(['course_id' => $this->course->id, 'title' => 'Week one']);
    foreach (['Variables', 'Loops'] as $title) {
        $lesson = Lesson::query()->create(['course_id' => $this->course->id, 'course_module_id' => $module->id, 'title' => $title, 'slug' => str($title)->slug().'-'.uniqid()]);
        LessonCompletion::query()->create(['lesson_id' => $lesson->id, 'user_id' => $this->steady->id, 'completed_at' => now()]);
    }
    Enrollment::query()->where('user_id', $this->steady->id)->update(['progress_percent' => 100]);

    LeaderboardPoint::query()->create(['user_id' => $this->steady->id, 'batch_id' => $this->batch->id, 'source' => 'manual', 'points' => 120, 'awarded_at' => now()]);
    LeaderboardPoint::query()->create(['user_id' => $this->slipping->id, 'batch_id' => $this->batch->id, 'source' => 'manual', 'points' => 20, 'awarded_at' => now()]);

    ActivityLog::query()->create(['user_id' => $this->steady->id, 'action' => 'lesson.opened', 'occurred_at' => now()->subDay()]);
    ActivityLog::query()->create(['user_id' => $this->slipping->id, 'action' => 'lesson.opened', 'occurred_at' => now()->subDays(12)]);
});

it('counts a student\'s figures from what was recorded', function () {
    $steady = app(Performance::class)->forEnrolment(Enrollment::query()->where('user_id', $this->steady->id)->sole());

    expect($steady['attendance'])->toBe(['held' => 4, 'attended' => 4, 'percent' => 100])
        ->and($steady['assignments'])->toMatchArray(['due' => 3, 'onTime' => 2, 'late' => 1, 'missing' => 0, 'percent' => 67])
        ->and($steady['quizzes']['average'])->toBe(70)
        ->and($steady['quizzes']['trend'])->toBe('up')
        ->and($steady['lessons'])->toBe(['done' => 2, 'total' => 2, 'percent' => 100])
        ->and($steady['rank'])->toMatchArray(['rank' => 1, 'outOf' => 2, 'points' => 120])
        ->and($steady['signals'])->toBeEmpty();

    $slipping = app(Performance::class)->forEnrolment(Enrollment::query()->where('user_id', $this->slipping->id)->sole());

    expect($slipping['attendance']['percent'])->toBe(25)
        ->and($slipping['assignments'])->toMatchArray(['missing' => 3, 'percent' => 0])
        ->and($slipping['quizzes']['trend'])->toBe('down')
        ->and(collect($slipping['signals'])->pluck('key')->all())->toBe(['attendance', 'missing', 'quiz', 'quiet']);
});

it('reads a trend only once there are two results to compare', function () {
    $performance = app(Performance::class);

    expect($performance->trend([70]))->toBeNull()
        ->and($performance->trend([70, 73]))->toBe('steady')
        ->and($performance->trend([80, 60, 50]))->toBe('down');
});

it('shows a student their own figures, and staff the same on the record', function () {
    $this->actingAs($this->steady)->get('/student/performance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('student/Performance')
            ->has('courses', 1)
            ->where('courses.0.attendance.percent', 100));

    $this->actingAs($this->owner)->get("/admin/students/{$this->slipping->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('record.performance.0.attendance.percent', 25));
});

it('gives a trainer the batch, sortable and filtered to who is slipping', function () {
    $trainer = User::factory()->admin()->create();
    $trainer->permissions()->attach(Permission::query()->where('key', 'students.view')->first());
    $path = "/admin/batches/{$this->batch->getRouteKey()}/performance";

    $this->actingAs($trainer)->get($path)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/batches/Performance')
            ->has('table.rows', 2)
            ->where('slipping', 1)
            // Lowest attendance first, which is the point of the screen.
            ->where('table.rows.0.student', 'Slipping Student')
            ->where('table.rows.0.attended', '25% (1 of 4)')
            ->where('table.rows.0.missing', 3)
            ->where('table.rows.1.on_time', '2 of 3')
            ->where('table.rows.1.quiz_average', '70%'));

    foreach (['any', 'attendance', 'missing', 'quiz', 'quiet'] as $signal) {
        $this->actingAs($trainer)->get("{$path}?filter[signal]={$signal}")
            ->assertInertia(fn ($page) => $page->has('table.rows', 1)->where('table.rows.0.student', 'Slipping Student'));
    }

    $this->actingAs($trainer)->get("{$path}?sort=-points")
        ->assertInertia(fn ($page) => $page->where('table.rows.0.student', 'Steady Student'));

    $csv = $this->actingAs($trainer)->get("{$path}?export=csv")->assertOk()->streamedContent();
    expect($csv)->toContain('Slipping Student')->toContain('Attendance below 75%');

    $nobody = User::factory()->admin()->create();
    $this->actingAs($nobody)->get($path)->assertForbidden();
});
