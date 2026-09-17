<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudentWarning;
use App\Models\User;
use App\Notifications\WarningIssued;
use App\Services\Lms\Warnings;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->owner = User::factory()->owner()->create();
    $this->student = User::factory()->student()->create();

    $this->course = Course::query()->create([
        'title' => 'Web development internship',
        'slug' => 'warnings-course-'.uniqid(),
        'type' => 'internship',
        'tagline' => 'Build something real',
        'summary' => 'Eight weeks, live.',
    ]);

    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id,
        'name' => 'September batch',
        'code' => 'WN-'.uniqid(),
        'starts_on' => today()->subWeek(),
    ]);

    Enrollment::query()->create([
        'user_id' => $this->student->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'enrolled_at' => now()->subWeek(),
        'has_paid' => true,
    ]);
});

function warn(string $reason = 'Three classes missed.'): StudentWarning
{
    return app(Warnings::class)->issue(
        student: test()->student,
        reason: $reason,
        batch: test()->batch,
        issuedBy: test()->owner,
    );
}

it('starts with a quiet word rather than a formal warning', function () {
    expect(warn()->level)->toBe('notice');
});

it('climbs a rung at a time', function () {
    expect(warn()->level)->toBe('notice')
        ->and(warn()->level)->toBe('warning')
        ->and(warn()->level)->toBe('escalation')
        // And stays at the top rather than falling off it.
        ->and(warn()->level)->toBe('escalation');
});

it('starts again at the bottom once the earlier ones are closed out', function () {
    $first = warn();
    app(Warnings::class)->resolve($first);

    expect(warn()->level)->toBe('notice');
});

it('tells the student every time, whatever the rung', function () {
    warn();

    Notification::assertSentTo($this->student, WarningIssued::class);
});

it('keeps the private note out of what the student is shown', function () {
    app(Warnings::class)->issue(
        student: $this->student,
        reason: 'You have missed three classes.',
        batch: $this->batch,
        issuedBy: $this->owner,
        privateNote: 'Coordinator says there are examinations on.',
    );

    $props = $this->actingAs($this->student)->get('/student/warnings')->inertiaProps();

    expect(json_encode($props['warnings']))
        ->toContain('You have missed three classes.')
        ->not->toContain('Coordinator says');
});

it('does not show one student another student’s notices', function () {
    warn();

    $other = User::factory()->student()->create();

    $props = $this->actingAs($other)->get('/student/warnings')->inertiaProps();

    expect($props['warnings'])->toBeEmpty();
});

it('records the acknowledgement once', function () {
    $warning = warn();

    $this->actingAs($this->student)
        ->post("/student/warnings/{$warning->id}/acknowledge")
        ->assertRedirect();

    $first = $warning->fresh()->acknowledged_at;

    $this->travel(5)->minutes();

    $this->actingAs($this->student)->post("/student/warnings/{$warning->id}/acknowledge");

    expect($warning->fresh()->acknowledged_at->toIso8601String())->toBe($first->toIso8601String());
});

it('will not let a student acknowledge somebody else’s notice', function () {
    $warning = warn();
    $other = User::factory()->student()->create();

    $this->actingAs($other)
        ->post("/student/warnings/{$warning->id}/acknowledge")
        ->assertNotFound();
});
