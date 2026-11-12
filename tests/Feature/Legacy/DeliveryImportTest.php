<?php

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LiveSession;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LegacyDatabase;

beforeEach(function () {
    LegacyDatabase::create();
    LegacyDatabase::createDelivery();
    Storage::fake('local');

    $this->student = LegacyDatabase::user(['email' => 'learner@example.test']);
    $this->program = LegacyDatabase::insert('training_programs', [
        'title' => 'Web development internship', 'slug' => 'web-dev-internship',
        'short_description' => 'Eight weeks, live.', 'price' => '4999.50', 'duration' => '8 weeks', 'level' => 'Intermediate',
        'curriculum' => json_encode([['title' => 'Foundations', 'topics' => ['HTML', 'CSS']], 'Capstone']),
    ]);
    $this->batch = LegacyDatabase::insert('batches', [
        'program_id' => $this->program, 'name' => 'June batch', 'start_date' => now()->subMonth()->toDateString(),
        'end_date' => now()->addMonth()->toDateString(), 'max_students' => 25,
        'schedule' => json_encode([['day' => 'Monday', 'start_time' => '19:30:00', 'end_time' => '21:00:00']]),
    ]);
});

function importAll($test, array $options = []): void
{
    $test->artisan('legacy:import', ['--all-generation-one' => true, ...$options])->assertSuccessful();
}

function lastReport(): array
{
    $files = Storage::disk('local')->files('legacy-reports');
    sort($files);

    return json_decode(Storage::disk('local')->get(end($files)), true);
}

it('turns a program into a draft course, prices in paise, with its syllabus', function () {
    importAll($this);

    $course = Course::query()->where('slug', 'web-dev-internship')->sole();
    expect($course->type)->toBe('internship')
        ->and($course->price)->toBe(499950)
        ->and($course->duration_weeks)->toBe(8)
        ->and($course->level)->toBe('intermediate')
        ->and($course->is_published)->toBeFalse()
        ->and($course->visibility)->toBe('public')
        ->and($course->syllabus)->toBe([
            ['module' => 'Foundations', 'topics' => ['HTML', 'CSS']],
            ['module' => 'Capstone', 'topics' => []],
        ]);

    expect(collect(lastReport()['steps']['courses']['warnings'])->pluck('code')->all())->toContain('type_guessed', 'draft_for_review');
});

it('keeps a private program inside the LMS, and maps a program onto a v3 course only when told to', function () {
    $private = LegacyDatabase::insert('training_programs', ['title' => 'Staff only', 'slug' => 'staff-only', 'is_public' => false]);
    $existing = Course::query()->create(['title' => 'Full stack', 'slug' => 'full-stack', 'type' => 'course', 'tagline' => 't', 'summary' => 's']);
    $mapped = LegacyDatabase::insert('training_programs', ['title' => 'Something else entirely', 'slug' => 'old-full-stack']);

    $map = tempnam(sys_get_temp_dir(), 'map');
    file_put_contents($map, json_encode(['old-full-stack' => 'full-stack', 'web-dev-internship' => ['type' => 'course']]));
    importAll($this, ['--course-map' => $map]);
    unlink($map);

    expect(Course::query()->where('slug', 'staff-only')->sole()->visibility)->toBe('lms_only')
        ->and(Course::query()->where('slug', 'web-dev-internship')->sole()->type)->toBe('course')
        ->and(Course::query()->where('title', 'Something else entirely')->exists())->toBeFalse();

    // The mapped v3 course is untouched.
    expect($existing->fresh()->title)->toBe('Full stack');
});

it('gives batches a code, the v3 schedule shape and a status from their dates', function () {
    importAll($this);

    $batch = Batch::query()->sole();
    expect($batch->code)->toBe("V2-{$this->batch}")
        ->and($batch->capacity)->toBe(25)
        ->and($batch->status)->toBe('running')
        ->and($batch->schedule)->toBe([['day' => 'Mon', 'from' => '19:30', 'to' => '21:00']]);
});

it('enrols students with a mapped status, never one still waiting to pay, and counts seats', function () {
    $waiting = LegacyDatabase::user();
    $dropped = LegacyDatabase::user();
    LegacyDatabase::insert('enrollments', ['student_id' => $this->student, 'program_id' => $this->program, 'batch_id' => $this->batch, 'status' => 'active', 'student_name' => 'x', 'student_email' => 'x', 'admin_notes' => 'Paid in cash at the office']);
    LegacyDatabase::insert('enrollments', ['student_id' => $dropped, 'program_id' => $this->program, 'batch_id' => $this->batch, 'status' => 'cancelled', 'student_name' => 'x', 'student_email' => 'x']);
    $pending = LegacyDatabase::insert('enrollments', ['student_id' => $waiting, 'program_id' => $this->program, 'status' => 'pending_payment', 'student_name' => 'x', 'student_email' => 'x']);

    importAll($this);

    $batch = Batch::query()->sole();
    expect(Enrollment::query()->count())->toBe(2)
        ->and(Enrollment::query()->where('status', 'dropped')->count())->toBe(1)
        ->and(Enrollment::query()->where('status', 'active')->sole()->batch_id)->toBe($batch->id)
        ->and($batch->seats_taken)->toBe(1);

    expect(collect(lastReport()['steps']['enrolments']['warnings'])->where('code', 'pending_payment')->pluck('source')->all())->toBe(["enrollments#{$pending}"])
        ->and(AuditLog::query()->where('action', 'enrolment.imported_notes')->sole()->changes['admin_notes']['to'])->toBe('Paid in cash at the office');
});

it('finds the batch through batch membership when the enrolment does not name one', function () {
    LegacyDatabase::insert('enrollments', ['student_id' => $this->student, 'program_id' => $this->program, 'status' => 'active', 'student_name' => 'x', 'student_email' => 'x']);
    LegacyDatabase::insert('batch_students', ['batch_id' => $this->batch, 'student_id' => $this->student, 'joined_at' => now()]);

    importAll($this);

    expect(Enrollment::query()->sole()->batch_id)->toBe(Batch::query()->sole()->id);
});

it('brings classes and attendance across as present, and invents no absences', function () {
    $other = LegacyDatabase::user();
    $past = LegacyDatabase::insert('live_classes', ['batch_id' => $this->batch, 'title' => 'Week 1', 'join_url' => 'https://meet.google.com/abc-defg-hij', 'scheduled_at' => now()->subWeek()]);
    LegacyDatabase::insert('live_classes', ['batch_id' => $this->batch, 'title' => 'Cancelled', 'join_url' => 'https://meet.google.com/x', 'scheduled_at' => now()->subDays(3), 'is_cancelled' => true]);
    LegacyDatabase::insert('live_classes', ['batch_id' => $this->batch, 'title' => 'Next week', 'join_url' => 'https://meet.google.com/y', 'scheduled_at' => now()->addWeek()]);
    LegacyDatabase::insert('attendances', ['live_class_id' => $past, 'student_id' => $this->student, 'joined_at' => now()->subWeek(), 'is_manual' => true]);

    importAll($this);

    expect(LiveSession::query()->where('title', 'Week 1')->sole()->status)->toBe('completed')
        ->and(LiveSession::query()->where('title', 'Cancelled')->sole()->status)->toBe('cancelled')
        ->and(LiveSession::query()->where('title', 'Next week')->sole()->status)->toBe('scheduled')
        ->and(LiveSession::query()->where('title', 'Week 1')->sole()->meet_link)->toBe('https://meet.google.com/abc-defg-hij');

    $attendance = Attendance::query()->sole();
    expect($attendance->status)->toBe('present')
        ->and($attendance->note)->toBe('Marked by hand on the old site')
        ->and(Attendance::query()->where('status', 'absent')->count())->toBe(0);
});

it('runs the whole chain again without a single duplicate', function () {
    LegacyDatabase::insert('enrollments', ['student_id' => $this->student, 'program_id' => $this->program, 'batch_id' => $this->batch, 'status' => 'active', 'student_name' => 'x', 'student_email' => 'x']);
    $class = LegacyDatabase::insert('live_classes', ['batch_id' => $this->batch, 'title' => 'Week 1', 'join_url' => 'https://meet.google.com/a', 'scheduled_at' => now()->subWeek()]);
    LegacyDatabase::insert('attendances', ['live_class_id' => $class, 'student_id' => $this->student, 'joined_at' => now()->subWeek()]);

    importAll($this);
    importAll($this);

    expect(Course::query()->count())->toBe(1)
        ->and(Batch::query()->count())->toBe(1)
        ->and(Enrollment::query()->count())->toBe(1)
        ->and(LiveSession::query()->count())->toBe(1)
        ->and(Attendance::query()->count())->toBe(1);

    $steps = lastReport()['steps'];
    expect($steps['courses']['unchanged'])->toBe(1)
        ->and($steps['enrolments']['unchanged'])->toBe(1)
        ->and($steps['attendance']['unchanged'])->toBe(1);
});
