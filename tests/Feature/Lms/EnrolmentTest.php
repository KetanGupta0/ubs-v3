<?php

use App\Models\Batch;
use App\Models\College;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\PaymentRequest;
use App\Models\Setting;
use App\Models\User;
use App\Services\Lms\Enroller;
use App\Support\Money;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    Setting::put('company.state', 'Madhya Pradesh', 'company');
    Setting::put('invoicing.prefix', 'UBS', 'invoicing');

    $this->owner = User::factory()->owner()->create();
    $this->student = User::factory()->student()->create();

    $this->course = Course::query()->create([
        'title' => 'Web development internship',
        'slug' => 'enrolment-course-'.uniqid(),
        'type' => 'internship',
        'tagline' => 'Build something real',
        'summary' => 'Eight weeks, live.',
        // Rupees fifteen thousand, held as paise like everything else.
        'price' => Money::toPaise(15000),
        'is_published' => true,
    ]);

    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id,
        'name' => 'September batch',
        'code' => 'EN-'.uniqid(),
        'starts_on' => today()->addWeek(),
        'capacity' => 2,
        'status' => 'upcoming',
        'is_published' => true,
    ]);
});

it('enrols a paid course unpaid, so the syllabus can be seen while the fee is settled', function () {
    $enrolment = app(Enroller::class)->enrol($this->student, $this->course, $this->batch, notify: false);

    expect($enrolment->has_paid)->toBeFalse()
        ->and($enrolment->payment_request_id)->not->toBeNull()
        ->and($enrolment->status)->toBe('active');
});

it('prices the fee from the course, not from the request', function () {
    $this->actingAs($this->student)->post('/student/enrol', [
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        // A hopeful extra field. Nothing reads it.
        'amount' => 1,
    ])->assertRedirect();

    $request = PaymentRequest::query()->sole();
    $advertised = Money::toPaise(15000);

    // The fee is treated as inclusive, so the student is asked for what the
    // page said rather than that plus tax. Rounding the tax to the paisa means
    // the exact figure is not always reachable, so it lands within a paisa and
    // never above.
    expect($request->total)->toBeLessThanOrEqual($advertised)
        ->toBeGreaterThanOrEqual($advertised - 1)
        ->and($request->subtotal + $request->tax)->toBe($request->total);
});

it('opens the paid lessons once the fee is settled', function () {
    $enrolment = app(Enroller::class)->enrol($this->student, $this->course, $this->batch, notify: false);

    app(Enroller::class)->markPaid($enrolment);

    expect($enrolment->fresh()->has_paid)->toBeTrue();
});

it('enrols free of charge when the fee is waived for a college group', function () {
    $enrolment = app(Enroller::class)
        ->enrol($this->student, $this->course, $this->batch, waiveFee: true, notify: false);

    expect($enrolment->has_paid)->toBeTrue()
        ->and($enrolment->payment_request_id)->toBeNull()
        ->and(PaymentRequest::query()->count())->toBe(0);
});

it('counts the seat, and gives it back when somebody drops', function () {
    $enrolment = app(Enroller::class)->enrol($this->student, $this->course, $this->batch, notify: false);

    expect($this->batch->fresh()->seats_taken)->toBe(1);

    app(Enroller::class)->drop($enrolment);

    expect($this->batch->fresh()->seats_taken)->toBe(0);
});

it('refuses a batch that is full', function () {
    foreach (range(1, 2) as $ignored) {
        app(Enroller::class)->enrol(
            User::factory()->student()->create(),
            $this->course,
            $this->batch,
            notify: false,
        );
    }

    $this->actingAs($this->student)
        ->post('/student/enrol', ['course_id' => $this->course->id, 'batch_id' => $this->batch->id])
        ->assertSessionHasErrors('batch_id');

    expect(Enrollment::query()->where('user_id', $this->student->id)->count())->toBe(0);
});

it('puts somebody back rather than failing when they re-enrol after dropping', function () {
    $first = app(Enroller::class)->enrol($this->student, $this->course, $this->batch, notify: false);
    app(Enroller::class)->drop($first);

    $second = app(Enroller::class)->enrol($this->student, $this->course, $this->batch, notify: false);

    expect($second->id)->toBe($first->id)
        ->and($second->status)->toBe('active')
        ->and(Enrollment::query()->count())->toBe(1);
});

/* ----------------------------------------------------- the college desk */

it('creates the accounts and enrols the whole list in one go', function () {
    $college = College::query()->create([
        'name' => 'Example Institute of Technology',
        'slug' => 'example-institute-'.uniqid(),
    ]);

    $this->actingAs($this->owner)->post("/admin/colleges/{$college->slug}/enrol", [
        'batch_id' => $this->batch->id,
        'waive_fee' => true,
        'students' => [
            ['name' => 'Asha Verma', 'email' => 'asha@example.test', 'enrollment_number' => '21CS045'],
            ['name' => 'Imran Qureshi', 'email' => 'imran@example.test'],
        ],
    ])->assertRedirect();

    expect(User::query()->whereIn('email', ['asha@example.test', 'imran@example.test'])->count())->toBe(2)
        ->and(Enrollment::query()->where('batch_id', $this->batch->id)->count())->toBe(2)
        // Waived, so nobody is chased for a fee their college is paying.
        ->and(PaymentRequest::query()->count())->toBe(0);

    $asha = User::query()->where('email', 'asha@example.test')->sole();

    expect($asha->profile->college_id)->toBe($college->id)
        ->and($asha->profile->enrollment_number)->toBe('21CS045');
});

it('matches somebody already on our books by email rather than duplicating them', function () {
    $college = College::query()->create([
        'name' => 'Example Institute of Technology',
        'slug' => 'example-institute-'.uniqid(),
    ]);

    $existing = User::factory()->student()->create(['email' => 'asha@example.test']);

    $this->actingAs($this->owner)->post("/admin/colleges/{$college->slug}/enrol", [
        'batch_id' => $this->batch->id,
        'waive_fee' => true,
        'students' => [['name' => 'Asha Verma', 'email' => 'ASHA@example.test']],
    ])->assertRedirect();

    expect(User::query()->where('email', 'asha@example.test')->count())->toBe(1)
        ->and(Enrollment::query()->where('user_id', $existing->id)->count())->toBe(1);
});
