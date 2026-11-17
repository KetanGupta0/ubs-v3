<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LeaderboardPoint;
use App\Models\User;
use App\Services\Lms\Activity;
use App\Services\Lms\Leaderboard;

beforeEach(function () {
    $this->course = Course::query()->create(['title' => 'Python basics', 'slug' => 'lb-'.uniqid(), 'type' => 'course', 'tagline' => 'x', 'summary' => 'x']);
    $this->morning = Batch::query()->create(['course_id' => $this->course->id, 'name' => 'Morning batch', 'code' => 'LB-'.uniqid()]);
    $this->evening = Batch::query()->create(['course_id' => $this->course->id, 'name' => 'Evening batch', 'code' => 'LB-'.uniqid()]);
    $this->other = Course::query()->create(['title' => 'SQL', 'slug' => 'lb-'.uniqid(), 'type' => 'course', 'tagline' => 'x', 'summary' => 'x']);

    $this->enrol = function (string $name, Batch $batch) {
        $student = User::factory()->student()->create(['name' => $name]);
        Enrollment::query()->create(['user_id' => $student->id, 'course_id' => $batch->course_id, 'batch_id' => $batch->id, 'enrolled_at' => now(), 'has_paid' => true]);

        return $student;
    };

    $this->award = fn (User $student, int $points, ?Batch $batch, $when = null, ?Course $course = null) => LeaderboardPoint::query()->create([
        'user_id' => $student->id, 'batch_id' => $batch?->id, 'course_id' => ($course ?? $this->course)->id,
        'source' => 'manual', 'points' => $points, 'awarded_at' => $when ?? now(),
    ]);

    $this->asha = ($this->enrol)('Asha Verma', $this->morning);
    $this->ravi = ($this->enrol)('Ravi Kumar', $this->morning);
    $this->late = ($this->enrol)('Late Joiner', $this->morning);
    $this->eve = ($this->enrol)('Eve Student', $this->evening);

    ($this->award)($this->asha, 200, $this->morning, now()->subWeeks(2));
    ($this->award)($this->ravi, 150, $this->morning, now()->subWeeks(2));
    ($this->award)($this->late, 40, $this->morning);
    ($this->award)($this->eve, 300, $this->evening, now()->subWeeks(2));
    ($this->award)($this->eve, 500, null, now()->subWeeks(2), $this->other);
});

it('ranks a batch, this week, a course and everybody', function () {
    $board = app(Leaderboard::class);

    expect(collect($board->forBatch($this->morning))->pluck('name')->all())->toBe(['Asha Verma', 'Ravi Kumar', 'Late Joiner'])
        // A late joiner can top the weekly board.
        ->and(collect($board->forBatchThisWeek($this->morning))->pluck('name')->all())->toBe(['Late Joiner'])
        ->and(collect($board->forCourse($this->course))->pluck('name')->all())->toBe(['Eve Student', 'Asha Verma', 'Ravi Kumar', 'Late Joiner'])
        ->and($board->allTime()[0])->toMatchArray(['name' => 'Eve Student', 'points' => 800]);

    expect($board->standingFor($this->ravi, $this->morning))->toMatchArray(['rank' => 2, 'outOf' => 3, 'topPoints' => 200])
        ->and($board->standingThisWeek($this->late, $this->morning))->toMatchArray(['rank' => 1, 'outOf' => 1])
        ->and($board->standingThisWeek($this->asha, $this->morning))->toBeNull()
        ->and($board->standingOnCourse($this->asha, $this->course))->toMatchArray(['rank' => 2, 'outOf' => 4])
        ->and($board->standingOverall($this->late))->toMatchArray(['rank' => 4, 'outOf' => 4]);
});

it('gives equal points the same place', function () {
    ($this->award)($this->ravi, 50, $this->morning, now()->subWeeks(2));

    $ranks = collect(app(Leaderboard::class)->forBatch($this->morning))->pluck('rank', 'name')->all();

    expect($ranks)->toBe(['Asha Verma' => 1, 'Ravi Kumar' => 1, 'Late Joiner' => 3])
        ->and(app(Leaderboard::class)->standingFor($this->late, $this->morning)['rank'])->toBe(3);
});

it('lets a student hide their name from other students, without moving anybody', function () {
    $this->actingAs($this->asha)->put('/student/leaderboard/visibility', ['show' => false])->assertSessionHas('success');
    expect($this->asha->fresh()->show_on_leaderboard)->toBeFalse();

    $this->actingAs($this->ravi)->get('/student/leaderboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('board', 'batch')
            ->where('boards.0.rows.0.name', 'A student')
            ->where('boards.0.rows.0.userId', null)
            ->where('boards.0.rows.0.hidden', true)
            ->where('boards.0.rows.0.rank', 1)
            ->where('boards.0.rows.1.name', 'Ravi Kumar')
            ->where('boards.0.rows.1.me', true)
            ->where('boards.0.mine.rank', 2));

    // They still see themselves, and staff always see who is who.
    $this->actingAs($this->asha)->get('/student/leaderboard')
        ->assertInertia(fn ($page) => $page->where('boards.0.rows.0.name', 'Asha Verma')->where('boards.0.rows.0.me', true)->where('showName', false));
    expect(app(Leaderboard::class)->forBatch($this->morning, revealNames: true)[0]['name'])->toBe('Asha Verma');
});

it('switches boards from the page, and only to boards that exist', function () {
    $this->actingAs($this->late)->get('/student/leaderboard?board=week')
        ->assertInertia(fn ($page) => $page->where('board', 'week')->where('boards.0.rows.0.name', 'Late Joiner'));

    $this->actingAs($this->late)->get('/student/leaderboard?board=course')
        ->assertInertia(fn ($page) => $page->where('board', 'course')->has('boards', 1)->where('boards.0.title', 'Python basics'));

    $this->actingAs($this->late)->get('/student/leaderboard?board=all')
        ->assertInertia(fn ($page) => $page->where('boards.0.mine.rank', 4));

    $this->actingAs($this->late)->get('/student/leaderboard?board=../../etc')
        ->assertInertia(fn ($page) => $page->where('board', 'batch'));
});

it('explains every way to earn points in words', function () {
    $this->actingAs($this->asha)->get('/student/leaderboard')
        ->assertInertia(fn ($page) => $page
            ->where('howItWorks', fn ($rules) => collect($rules)->pluck('source')->every(fn ($label) => ! str_contains($label, '.') && ! preg_match('/[a-z][A-Z]/', $label))));

    // Every rule that pays has its words.
    expect(array_keys(array_filter(Activity::POINTS)))
        ->toEqualCanonicalizing(array_keys(Activity::POINT_LABELS));
});
