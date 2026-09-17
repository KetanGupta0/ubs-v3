<?php

use App\Models\Assignment;
use App\Models\Batch;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\InternshipDocument;
use App\Models\Submission;
use App\Models\User;
use App\Services\Lms\Credentials;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->student = User::factory()->student()->create(['name' => 'Asha Verma']);

    $this->course = Course::query()->create([
        'title' => 'Web development internship',
        'slug' => 'credentials-course-'.uniqid(),
        'type' => 'internship',
        'tagline' => 'Build something real',
        'summary' => 'Eight weeks, live.',
        'pass_percent' => 50,
        'issues_certificate' => true,
    ]);

    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id,
        'name' => 'September batch',
        'code' => 'CR-'.uniqid(),
        'starts_on' => today()->subWeeks(8),
        'ends_on' => today()->subWeek(),
    ]);

    $this->enrolment = Enrollment::query()->create([
        'user_id' => $this->student->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'enrolled_at' => now()->subWeeks(8),
        'has_paid' => true,
    ]);
});

function markedAt(int $marks, int $outOf = 100): void
{
    $assignment = Assignment::query()->create([
        'course_id' => test()->course->id,
        'title' => 'Project '.uniqid(),
        'brief' => 'Build the thing.',
        'max_marks' => $outOf,
        'is_published' => true,
    ]);

    Submission::query()->create([
        'assignment_id' => $assignment->id,
        'user_id' => test()->student->id,
        'submitted_at' => now()->subWeek(),
        'status' => 'evaluated',
        'marks' => $marks,
    ]);
}

/* ------------------------------------------------------------ issuing */

it('refuses a certificate for a student who is not passing', function () {
    markedAt(20);

    expect(fn () => app(Credentials::class)->issueCertificate($this->enrolment->fresh(), $this->owner))
        ->toThrow(RuntimeException::class);

    expect(Certificate::query()->count())->toBe(0);
});

it('issues one when the marks are there', function () {
    markedAt(80);

    $certificate = app(Credentials::class)->issueCertificate($this->enrolment->fresh(), $this->owner);

    expect($certificate->number)->not->toBeEmpty()
        ->and($certificate->verification_code)->not->toBeEmpty()
        ->and((float) $certificate->final_percent)->toBe(80.0)
        ->and($certificate->isValid())->toBeTrue();
});

it('issues only one certificate per enrolment', function () {
    markedAt(80);

    $first = app(Credentials::class)->issueCertificate($this->enrolment->fresh(), $this->owner);
    $second = app(Credentials::class)->issueCertificate($this->enrolment->fresh(), $this->owner);

    expect($second->id)->toBe($first->id)
        ->and(Certificate::query()->count())->toBe(1);
});

it('lets the decision be overridden, on the record', function () {
    markedAt(20);

    $this->actingAs($this->owner)
        ->post("/admin/enrolments/{$this->enrolment->id}/certificate", ['force' => true])
        ->assertRedirect();

    expect(Certificate::query()->count())->toBe(1)
        ->and(app('db')->table('audit_logs')->where('action', 'certificate.issued')->count())->toBe(1);
});

it('uses a verification code with no letter O or digit zero in it', function () {
    markedAt(80);

    $code = app(Credentials::class)->issueCertificate($this->enrolment->fresh(), $this->owner)->verification_code;

    expect($code)->not->toContain('O')->not->toContain('0');
});

/* --------------------------------------------------------- verifying */

it('confirms a genuine certificate to anybody, without an account', function () {
    markedAt(80);

    $certificate = app(Credentials::class)->issueCertificate($this->enrolment->fresh(), $this->owner);

    $this->get("/verify/{$certificate->verification_code}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Verify')
            ->where('result.found', true)
            ->where('result.valid', true)
            ->where('result.holder', 'Asha Verma'));
});

it('says a withdrawn certificate was withdrawn rather than failing to find it', function () {
    markedAt(80);

    $certificate = app(Credentials::class)->issueCertificate($this->enrolment->fresh(), $this->owner);

    $this->actingAs($this->owner)
        ->post("/admin/certificates/{$certificate->id}/revoke", ['reason' => 'Issued against the wrong batch.'])
        ->assertRedirect();

    $this->get("/verify/{$certificate->verification_code}")
        ->assertInertia(fn ($page) => $page
            ->where('result.found', true)
            ->where('result.valid', false));
});

it('answers a made up code the same way as a mistyped one', function () {
    $first = $this->get('/verify/UBSC1111111')->inertiaProps();
    $second = $this->get('/verify/UBSC2222222')->inertiaProps();

    expect($first['result'])->toBe(['found' => false])
        ->and($second['result'])->toBe($first['result']);
});

it('never puts a holder contact detail on the public page', function () {
    markedAt(80);

    $certificate = app(Credentials::class)->issueCertificate($this->enrolment->fresh(), $this->owner);

    $props = $this->get("/verify/{$certificate->verification_code}")->inertiaProps();

    expect(json_encode($props['result']))
        ->not->toContain($this->student->email)
        ->not->toContain((string) $this->student->mobile);
});

/* ----------------------------------------------- internship documents */

it('issues each kind of internship document once', function () {
    foreach (InternshipDocument::KINDS as $kind) {
        $this->actingAs($this->owner)
            ->post("/admin/enrolments/{$this->enrolment->id}/documents", ['kind' => $kind])
            ->assertRedirect();
    }

    expect(InternshipDocument::query()->count())->toBe(count(InternshipDocument::KINDS));

    $this->actingAs($this->owner)
        ->post("/admin/enrolments/{$this->enrolment->id}/documents", ['kind' => InternshipDocument::KINDS[0]]);

    expect(InternshipDocument::query()->count())->toBe(count(InternshipDocument::KINDS));
});

it('verifies an internship document by its own code', function () {
    $this->actingAs($this->owner)
        ->post("/admin/enrolments/{$this->enrolment->id}/documents", ['kind' => 'offer_letter']);

    $document = InternshipDocument::query()->sole();

    $this->get("/verify/{$document->verification_code}")
        ->assertInertia(fn ($page) => $page->where('result.found', true));
});
