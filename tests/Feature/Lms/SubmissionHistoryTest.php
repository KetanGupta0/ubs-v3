<?php

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('private');

    $this->owner = User::factory()->owner()->create();
    $this->student = User::factory()->student()->create(['name' => 'Rohit Das']);

    $course = Course::query()->create([
        'title' => 'Laravel for working developers', 'slug' => 'history-'.uniqid(), 'type' => 'course',
        'tagline' => 'x', 'summary' => 'y', 'is_published' => true,
    ]);
    $batch = Batch::query()->create(['course_id' => $course->id, 'name' => 'Nov', 'code' => 'SH-'.uniqid(), 'starts_on' => today()]);
    Enrollment::query()->create([
        'user_id' => $this->student->id, 'course_id' => $course->id, 'batch_id' => $batch->id,
        'enrolled_at' => now(), 'has_paid' => true,
    ]);

    $this->assignment = Assignment::query()->create([
        'course_id' => $course->id, 'title' => 'Build a booking API', 'brief' => 'REST, tested.',
        'max_marks' => 100, 'due_at' => now()->addWeek(), 'is_published' => true,
    ]);
});

function handIn($test, array $payload = [])
{
    return $test->actingAs($test->student)->post("/student/assignments/{$test->assignment->id}", [
        'notes' => 'First go',
        ...$payload,
    ]);
}

function markIt($test, string $status, int $marks = 70, string $feedback = 'Solid. Add tests for the edge cases.')
{
    $submission = Submission::query()->sole();

    return $test->actingAs($test->owner)->post("/admin/marking/submissions/{$submission->id}", [
        'marks' => $marks, 'feedback' => $feedback, 'status' => $status,
    ]);
}

it('keeps the first version and its feedback when the student hands in a new one', function () {
    handIn($this, ['files' => [UploadedFile::fake()->create('v1.zip', 20)]]);
    markIt($this, 'returned', feedback: 'The tests are missing. Add them and send it again.');

    handIn($this, ['notes' => 'Second go, with tests', 'files' => [UploadedFile::fake()->create('v2.zip', 20)]])
        ->assertSessionHasNoErrors();

    $submission = Submission::query()->sole();
    $first = $submission->versions()->sole();

    expect($submission->version)->toBe(2)
        ->and($submission->notes)->toBe('Second go, with tests')
        ->and($submission->feedback)->toBeNull()
        ->and($submission->status)->toBe('submitted')
        ->and(collect($submission->files)->pluck('name')->all())->toBe(['v2.zip'])
        ->and($first->version)->toBe(1)
        ->and($first->notes)->toBe('First go')
        ->and($first->feedback)->toBe('The tests are missing. Add them and send it again.')
        ->and(collect($first->files)->pluck('name')->all())->toBe(['v1.zip']);
});

it('keeps the files already handed in when a new version brings none', function () {
    handIn($this, ['files' => [UploadedFile::fake()->create('report.pdf', 20)]]);
    handIn($this, ['notes' => 'Fixed a typo in the notes']);

    expect(collect(Submission::query()->sole()->files)->pluck('name')->all())->toBe(['report.pdf']);
});

it('will not let a student overwrite work that has been marked', function () {
    handIn($this);
    markIt($this, 'evaluated', marks: 82);

    handIn($this, ['notes' => 'Trying my luck'])->assertSessionHasErrors('submission');

    $submission = Submission::query()->sole();

    expect($submission->marks)->toBe(82)
        ->and($submission->notes)->toBe('First go')
        ->and($submission->versions()->count())->toBe(0);
});

it('lets the student resubmit once marked work is sent back, and keeps the mark on record', function () {
    handIn($this);
    markIt($this, 'evaluated', marks: 55);
    markIt($this, 'returned', feedback: 'You can do better than 55. Have another go.');

    // The 55 it replaced is in the audit log.
    $audit = AuditLog::query()->where('subject_type', Submission::class)->latest('id')->first();
    expect($audit->changes['marks'])->toBe(['from' => 55, 'to' => null]);

    handIn($this, ['notes' => 'Better this time'])->assertSessionHasNoErrors();

    expect(Submission::query()->sole()->version)->toBe(2);
});

it('shows the history to the student and to the trainer, files included', function () {
    handIn($this, ['files' => [UploadedFile::fake()->create('v1.zip', 20)]]);
    markIt($this, 'returned', feedback: 'Needs tests.');
    handIn($this, ['notes' => 'With tests']);

    $submission = Submission::query()->sole();

    $this->actingAs($this->student)->get("/student/assignments/{$this->assignment->id}")
        ->assertInertia(fn ($page) => $page
            ->where('submission.version', 2)
            ->where('submission.canResubmit', true)
            ->has('submission.history', 1)
            ->where('submission.history.0.feedback', 'Needs tests.'));

    $this->actingAs($this->owner)->get("/admin/marking/submissions/{$submission->id}")
        ->assertInertia(fn ($page) => $page->has('history', 1)->where('submission.version', 2));

    // The first version's file is still there, for both of them.
    $this->actingAs($this->student)->get("/student/assignments/{$this->assignment->id}/files/0?version=1")->assertOk();
    $this->actingAs($this->owner)->get("/admin/marking/submissions/{$submission->id}/files/0?version=1")->assertOk();
    $this->actingAs($this->owner)->get("/admin/marking/submissions/{$submission->id}/files/0?version=9")->assertNotFound();
});
