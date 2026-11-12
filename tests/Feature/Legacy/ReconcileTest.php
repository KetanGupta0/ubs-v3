<?php

use App\Models\Certificate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\LegacyDatabase;

beforeEach(function () {
    LegacyDatabase::createAll();
    Storage::fake('local');
    Storage::fake('private');

    // A small made up school: two students, a paid course, a class, a quiz, a certificate.
    $asha = LegacyDatabase::user(['email' => 'asha@example.test']);
    $ravi = LegacyDatabase::user(['email' => 'ravi@example.test']);
    $program = LegacyDatabase::insert('training_programs', ['title' => 'Data course', 'slug' => 'data-course', 'price' => '4999.00']);
    $batch = LegacyDatabase::insert('batches', ['program_id' => $program, 'name' => 'June']);
    $enrolment = LegacyDatabase::insert('enrollments', ['student_id' => $asha, 'program_id' => $program, 'batch_id' => $batch, 'status' => 'active', 'student_name' => 'x', 'student_email' => 'x']);
    LegacyDatabase::insert('enrollments', ['student_id' => $ravi, 'program_id' => $program, 'status' => 'pending_payment', 'student_name' => 'x', 'student_email' => 'x']);
    LegacyDatabase::insert('payments', ['student_id' => $asha, 'enrollment_id' => $enrolment, 'amount' => '2499.50', 'status' => 'paid', 'razorpay_payment_id' => 'pay_1', 'updated_at' => '2026-06-01 10:00:00']);
    LegacyDatabase::insert('payments', ['student_id' => $asha, 'enrollment_id' => $enrolment, 'amount' => '2499.50', 'status' => 'paid', 'method' => 'cash', 'updated_at' => '2026-07-01 10:00:00']);
    $class = LegacyDatabase::insert('live_classes', ['batch_id' => $batch, 'title' => 'Week 1', 'join_url' => 'https://meet.google.com/a', 'scheduled_at' => '2026-06-02 19:00:00']);
    LegacyDatabase::insert('attendances', ['live_class_id' => $class, 'student_id' => $asha, 'joined_at' => '2026-06-02 19:05:00']);
    $quiz = LegacyDatabase::insert('quizzes', ['batch_id' => $batch, 'title' => 'Check']);
    LegacyDatabase::insert('quiz_attempts', ['quiz_id' => $quiz, 'student_id' => $asha, 'attempt_number' => 1, 'started_at' => '2026-06-03 10:00:00', 'score' => '7.5', 'total_marks' => 10, 'percentage' => 75, 'is_graded' => true]);
    $this->token = 'Ab'.Str::random(62);
    LegacyDatabase::insert('certificates', ['student_id' => $asha, 'batch_id' => $batch, 'program_id' => $program, 'certificate_number' => 'CERT-2026-00001', 'token' => $this->token]);

    $this->artisan('legacy:import', ['--all-generation-one' => true])->assertSuccessful();
});

it('passes when everything came across, to the paisa', function () {
    $this->artisan('legacy:reconcile')->assertSuccessful();

    $file = collect(Storage::disk('local')->files('legacy-reports'))->first(fn ($name) => str_contains($name, 'reconcile'));
    $report = json_decode(Storage::disk('local')->get($file), true);

    expect($report['passed'])->toBeTrue()
        ->and(collect($report['checks'])->firstWhere('check', 'Paid in total, in paise')['source'])->toBe(499900)
        ->and(json_encode($report))->not->toContain('@example.test');
});

it('fails when a single paisa is out', function () {
    Transaction::query()->where('gateway_payment_id', 'pay_1')->update(['amount' => 249949]);

    $this->artisan('legacy:reconcile')->assertFailed();
});

it('fails when an old QR link would no longer verify', function () {
    Certificate::query()->update(['legacy_token' => strtoupper($this->token)]);

    $this->artisan('legacy:reconcile')->assertFailed();
});

it('fails when a student did not come across', function () {
    User::query()->where('email', 'ravi@example.test')->forceDelete();

    $this->artisan('legacy:reconcile')->assertFailed();
});
