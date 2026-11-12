<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Permission;
use App\Models\Registration;
use App\Models\RegistrationForm;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PaymentRequested;
use App\Notifications\RegistrationApproved;
use App\Notifications\RegistrationRejected;
use App\Notifications\RegistrationToConfirm;
use App\Notifications\WelcomeCredentials;
use App\Services\Lms\Enroller;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(PermissionSeeder::class);

    Setting::put('company.state', 'Madhya Pradesh', 'company');
    Setting::put('invoicing.prefix', 'UBS', 'invoicing');

    $this->owner = User::factory()->owner()->create();

    $this->course = Course::query()->create([
        'title' => 'Data analytics internship',
        'slug' => 'reg-course-'.uniqid(),
        'type' => 'internship',
        'tagline' => 'Real data, real questions',
        'summary' => 'Six weeks.',
        'price' => Money::toPaise(5000),
        'is_published' => true,
        'visibility' => 'lms_only',
    ]);

    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id,
        'name' => 'December batch',
        'code' => 'RG-'.uniqid(),
        'starts_on' => today()->addWeeks(2),
        'capacity' => 40,
        'status' => 'upcoming',
        'is_published' => true,
    ]);

    $this->makeForm = fn (array $attributes = []) => RegistrationForm::query()->create([
        'slug' => 'campus-'.uniqid(),
        'title' => 'Campus drive',
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'fee' => 'course',
        'approval' => 'auto',
        'fields' => ['college'],
        'questions' => [
            ['key' => 'qlaptop', 'label' => 'Do you have a laptop?', 'type' => 'yesno', 'required' => true, 'options' => []],
            ['key' => 'qtrack', 'label' => 'Track', 'type' => 'choice', 'required' => false, 'options' => ['Python', 'SQL']],
        ],
        'is_active' => true,
        ...$attributes,
    ]);

    $this->entry = fn (array $overrides = []) => [
        'name' => 'Ritika Sharma',
        'email' => 'ritika@example.test',
        'mobile' => '98765 43210',
        'details' => ['college' => 'Government Engineering College'],
        'answers' => ['qlaptop' => 'yes', 'qtrack' => 'SQL'],
        ...$overrides,
    ];
});

function registrationStaff(array $keys): User
{
    $staff = User::factory()->admin()->create();
    $staff->permissions()->attach(Permission::query()->whereIn('key', $keys)->get());

    return $staff->fresh();
}

/* --------------------------------------------------------- public form */

it('shows an open form, kept out of search, for a course that is otherwise LMS only', function () {
    $form = ($this->makeForm)();

    $this->get($form->path())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/Registration')
            ->where('form.state', 'open')
            ->where('form.fee', Money::display(Money::toPaise(5000)).', inclusive of tax')
            ->where('course.title', 'Data analytics internship')
            ->where('batch.name', 'December batch')
            ->where('seo.noindex', true)
            ->has('form.questions', 2));

    // The course itself stays off the public site.
    $this->get("/internships/{$this->course->slug}")->assertNotFound();
    $this->get('/sitemap.xml')->assertDontSee($form->path());
});

it('404s for a form that does not exist', function () {
    $this->get('/join/nothing-here')->assertNotFound();
});

it('says when a form is not open yet, closed, full or switched off, and takes nothing', function (array $attributes, string $state) {
    $form = ($this->makeForm)($attributes);

    if ($state === 'full') {
        Registration::query()->create(['registration_form_id' => $form->id, 'name' => 'A', 'email' => 'a@example.test', 'status' => 'approved']);
    }

    $this->get($form->path())->assertInertia(fn ($page) => $page->where('form.state', $state));

    $this->post($form->path(), ($this->entry)())->assertRedirect($form->path());
    expect(Registration::query()->where('email', 'ritika@example.test')->exists())->toBeFalse();
})->with([
    'not yet' => [['opens_at' => now()->addDay()], 'not_yet'],
    'past its date' => [['closes_at' => now()->subMinute()], 'closed'],
    'switched off' => [['is_active' => false], 'closed'],
    'full' => [['capacity' => 1], 'full'],
]);

it('closes a form whose course has been taken off the site', function () {
    $form = ($this->makeForm)();
    $this->course->update(['is_published' => false]);

    expect($form->fresh()->state())->toBe('closed');
});

it('gives a rejected or declined registration its place back', function () {
    $form = ($this->makeForm)(['capacity' => 1]);
    Registration::query()->create(['registration_form_id' => $form->id, 'name' => 'A', 'email' => 'a@example.test', 'status' => 'declined']);

    expect($form->state())->toBe('open');
});

it('checks every answer against the form', function () {
    $form = ($this->makeForm)();

    $this->post($form->path(), ($this->entry)([
        'details' => [],
        'answers' => ['qtrack' => 'Cobol'],
        'mobile' => '12',
    ]))->assertSessionHasErrors(['details.college', 'answers.qlaptop', 'answers.qtrack', 'mobile']);

    expect(Registration::query()->count())->toBe(0);
});

it('refuses a submission that filled the hidden field', function () {
    $form = ($this->makeForm)();

    $this->post($form->path(), ($this->entry)(['website' => 'https://spam.example']))
        ->assertSessionHasErrors('website');

    expect(Registration::query()->count())->toBe(0);
});

/* ------------------------------------------------------- the same answer */

it('answers a new address, an existing student, a staff account and a repeat identically', function () {
    $form = ($this->makeForm)();
    User::factory()->student()->create(['email' => 'known@example.test']);
    User::factory()->admin()->create(['email' => 'staff@example.test']);

    $answers = collect(['new@example.test', 'known@example.test', 'staff@example.test', 'new@example.test'])
        ->map(function (string $email) use ($form) {
            $response = $this->post($form->path(), ($this->entry)(['email' => $email]));

            return [
                'status' => $response->getStatusCode(),
                'location' => $response->headers->get('Location'),
                'flash' => session('registrationSent'),
                'errors' => session('errors')?->all(),
            ];
        });

    expect($answers->unique()->count())->toBe(1)
        ->and($answers->first()['flash'])->toBeTrue()
        ->and(Registration::query()->where('email', 'new@example.test')->count())->toBe(1);

    // And the page that follows says only "check your email".
    $this->get($form->path())->assertInertia(fn ($page) => $page->where('flash.registrationSent', true));
});

/* ----------------------------------------------------------- new address */

it('makes an account for a new address and puts it on the course, fee raised in the welcome', function () {
    $form = ($this->makeForm)();

    $this->post($form->path(), ($this->entry)(['email' => 'Ritika@Example.test']))->assertRedirect($form->path());

    $registration = Registration::query()->sole();
    $student = User::query()->where('email', 'ritika@example.test')->sole();

    expect($student->role)->toBe(Role::Student)
        ->and($student->must_change_password)->toBeTrue()
        ->and($student->mobile)->toBe('+919876543210')
        ->and($registration->status)->toBe('approved')
        ->and($registration->account_created)->toBeTrue()
        ->and($registration->details)->toBe(['college' => 'Government Engineering College'])
        ->and($registration->answers)->toBe(['qlaptop' => 'yes', 'qtrack' => 'SQL']);

    $enrolment = Enrollment::query()->where('user_id', $student->id)->sole();
    expect($enrolment->batch_id)->toBe($this->batch->id)
        ->and($enrolment->source)->toBe('form')
        ->and($enrolment->has_paid)->toBeFalse()
        ->and($enrolment->payment_request_id)->not->toBeNull();

    // One message: the welcome carries the fee, so no separate payment request.
    Notification::assertSentTo($student, WelcomeCredentials::class, fn ($n) => str_contains($n->personalNote, 'The fee of')
        && str_contains($n->personalNote, 'Data analytics internship'));
    Notification::assertNotSentTo($student, PaymentRequested::class);
    Notification::assertNotSentTo($student, RegistrationApproved::class);
});

it('keeps a mobile number already on another account off the new one', function () {
    $form = ($this->makeForm)();
    User::factory()->student()->create(['mobile' => '+919876543210']);

    $this->post($form->path(), ($this->entry)());

    expect(User::query()->where('email', 'ritika@example.test')->sole()->mobile)->toBeNull()
        ->and(Registration::query()->sole()->mobile)->toBe('+919876543210');
});

it('waives the fee for everybody when the form is free', function () {
    $form = ($this->makeForm)(['fee' => 'free']);

    $this->post($form->path(), ($this->entry)());

    $enrolment = Enrollment::query()->sole();
    expect($enrolment->has_paid)->toBeTrue()->and($enrolment->payment_request_id)->toBeNull();
});

it('holds a new account\'s registration for review when the form says so', function () {
    $form = ($this->makeForm)(['approval' => 'review']);

    $this->post($form->path(), ($this->entry)());

    $registration = Registration::query()->sole();
    expect($registration->status)->toBe('awaiting_review')
        ->and(Enrollment::query()->count())->toBe(0);

    Notification::assertSentTo($registration->user, WelcomeCredentials::class, fn ($n) => str_contains($n->personalNote, 'The team will look at your registration'));
});

/* ------------------------------------------------------ existing student */

it('asks an existing student to confirm and enrols nobody until they do', function () {
    $form = ($this->makeForm)();
    $student = User::factory()->student()->create(['email' => 'ritika@example.test', 'name' => 'Ritika S']);

    $this->post($form->path(), ($this->entry)(['name' => 'Anybody At All']));

    $registration = Registration::query()->sole();
    expect($registration->status)->toBe('awaiting_confirmation')
        ->and($registration->user_id)->toBe($student->id)
        ->and(Enrollment::query()->count())->toBe(0);

    // The message repeats nothing the sender typed.
    Notification::assertSentTo($student, RegistrationToConfirm::class, function ($notification) use ($student) {
        $mail = $notification->toMail($student);

        return ! str_contains(json_encode($mail->toArray()), 'Anybody At All');
    });
    Notification::assertNotSentTo($student, WelcomeCredentials::class);

    $this->actingAs($student)->get('/student')->assertOk()->assertInertia(fn ($page) => $page->where('registrations.confirm', 1));
    $this->actingAs($student)->get('/student/registrations')->assertInertia(fn ($page) => $page
        ->component('student/registrations/Index')
        ->where('registrations.0.status', 'awaiting_confirmation'));
});

it('puts a student on the course when they confirm, and sends them to pay', function () {
    $form = ($this->makeForm)();
    $student = User::factory()->student()->create(['email' => 'ritika@example.test']);
    $this->post($form->path(), ($this->entry)());
    $registration = Registration::query()->sole();

    $response = $this->actingAs($student)->post("/student/registrations/{$registration->id}/confirm");

    $enrolment = Enrollment::query()->where('user_id', $student->id)->sole();
    $response->assertRedirect("/student/payments/{$enrolment->payment_request_id}");
    expect($registration->fresh()->status)->toBe('approved')
        ->and($registration->fresh()->confirmed_at)->not->toBeNull();
});

it('sends a confirmed registration on a reviewed form to the team', function () {
    $form = ($this->makeForm)(['approval' => 'review']);
    $student = User::factory()->student()->create(['email' => 'ritika@example.test']);
    $this->post($form->path(), ($this->entry)());
    $registration = Registration::query()->sole();

    $this->actingAs($student)->post("/student/registrations/{$registration->id}/confirm")->assertRedirect();

    expect($registration->fresh()->status)->toBe('awaiting_review')
        ->and(Enrollment::query()->count())->toBe(0);
});

it('lets the holder say it was not them', function () {
    $form = ($this->makeForm)(['capacity' => 1]);
    $student = User::factory()->student()->create(['email' => 'ritika@example.test']);
    $this->post($form->path(), ($this->entry)());
    $registration = Registration::query()->sole();

    $this->actingAs($student)->post("/student/registrations/{$registration->id}/decline")->assertRedirect();

    expect($registration->fresh()->status)->toBe('declined')
        ->and(Enrollment::query()->count())->toBe(0)
        ->and($form->fresh()->state())->toBe('open');
});

it('does not let one student see or answer another\'s registration', function () {
    $form = ($this->makeForm)();
    User::factory()->student()->create(['email' => 'ritika@example.test']);
    $this->post($form->path(), ($this->entry)());
    $registration = Registration::query()->sole();

    $stranger = User::factory()->student()->create();

    $this->actingAs($stranger)->post("/student/registrations/{$registration->id}/confirm")->assertNotFound();
    $this->actingAs($stranger)->get('/student/registrations')->assertInertia(fn ($page) => $page->has('registrations', 0));
    expect($registration->fresh()->status)->toBe('awaiting_confirmation');
});

it('marks a student already on the course as approved and sends nothing', function () {
    $form = ($this->makeForm)();
    $student = User::factory()->student()->create(['email' => 'ritika@example.test']);
    $existing = app(Enroller::class)->enrol($student, $this->course, $this->batch, notify: false);

    $this->post($form->path(), ($this->entry)());

    expect(Registration::query()->sole())
        ->status->toBe('approved')
        ->enrollment_id->toBe($existing->id);
    Notification::assertNotSentTo($student, RegistrationToConfirm::class);
});

it('sends nothing to a staff address and will not put it on a course', function () {
    $form = ($this->makeForm)(['approval' => 'review']);
    $staff = User::factory()->admin()->create(['email' => 'ritika@example.test']);

    $this->post($form->path(), ($this->entry)());
    $registration = Registration::query()->sole();

    expect($registration->status)->toBe('awaiting_review')
        ->and($registration->note)->toContain('admin account');
    Notification::assertNothingSentTo($staff);

    $this->actingAs($this->owner)
        ->post("/admin/registration-forms/{$form->id}/registrations/{$registration->id}/approve")
        ->assertSessionHas('error');
    expect(Enrollment::query()->count())->toBe(0);
});

/* ------------------------------------------------------- staff decisions */

it('lets staff approve a reviewed registration, telling the student once, with the student\'s own payment link', function () {
    $form = ($this->makeForm)(['approval' => 'review']);
    $this->post($form->path(), ($this->entry)());
    $registration = Registration::query()->sole();

    $this->actingAs($this->owner)
        ->post("/admin/registration-forms/{$form->id}/registrations/{$registration->id}/approve")
        ->assertSessionHas('success');

    $registration->refresh();
    expect($registration->status)->toBe('approved')
        ->and($registration->decided_by)->toBe($this->owner->id);

    Notification::assertSentTo($registration->user, RegistrationApproved::class, function ($notification) use ($registration) {
        $mail = $notification->toMail($registration->user);

        return $mail->actionUrl === url("/student/payments/{$registration->enrollment->payment_request_id}");
    });
    Notification::assertNotSentTo($registration->user, PaymentRequested::class);

    expect(AuditLog::query()->where('action', 'registration.approved')->where('actor_id', $this->owner->id)->exists())->toBeTrue();
});

it('will not approve what the address holder has not confirmed, but can reject it without telling them', function () {
    $form = ($this->makeForm)(['approval' => 'review']);
    $student = User::factory()->student()->create(['email' => 'ritika@example.test']);
    $this->post($form->path(), ($this->entry)());
    $registration = Registration::query()->sole();

    $this->actingAs($this->owner)
        ->post("/admin/registration-forms/{$form->id}/registrations/{$registration->id}/approve")
        ->assertSessionHas('error');
    expect(Enrollment::query()->count())->toBe(0);

    $this->actingAs($this->owner)
        ->post("/admin/registration-forms/{$form->id}/registrations/{$registration->id}/reject", ['reason' => 'Batch is for third years.'])
        ->assertSessionHas('success');

    expect($registration->fresh()->status)->toBe('rejected');
    Notification::assertNotSentTo($student, RegistrationRejected::class);

    // Rejecting does not open a way round the confirmation either.
    $this->actingAs($this->owner)
        ->post("/admin/registration-forms/{$form->id}/registrations/{$registration->id}/approve")
        ->assertSessionHas('error');
    expect(Enrollment::query()->count())->toBe(0);
});

it('tells a confirmed applicant they were rejected, with the reason', function () {
    $form = ($this->makeForm)(['approval' => 'review']);
    $this->post($form->path(), ($this->entry)());
    $registration = Registration::query()->sole();

    $this->actingAs($this->owner)
        ->post("/admin/registration-forms/{$form->id}/registrations/{$registration->id}/reject", ['reason' => 'Batch is for third years.']);

    Notification::assertSentTo($registration->user, RegistrationRejected::class, fn ($n) => $n->registration->note === 'Batch is for third years.');
});

it('refuses to approve into a full batch, and moves a registration and its enrolment between batches', function () {
    $form = ($this->makeForm)(['approval' => 'review']);
    $this->post($form->path(), ($this->entry)());
    $registration = Registration::query()->sole();

    $this->batch->update(['capacity' => 1, 'seats_taken' => 1]);
    $this->actingAs($this->owner)
        ->post("/admin/registration-forms/{$form->id}/registrations/{$registration->id}/approve")
        ->assertSessionHas('error', 'December batch is full. Move this registration to another batch first.');

    $january = Batch::query()->create([
        'course_id' => $this->course->id, 'name' => 'January batch', 'code' => 'RG-'.uniqid(),
        'starts_on' => today()->addMonth(), 'capacity' => 40, 'status' => 'upcoming', 'is_published' => true,
    ]);

    $this->actingAs($this->owner)
        ->put("/admin/registration-forms/{$form->id}/registrations/{$registration->id}/batch", ['batch_id' => $january->id])
        ->assertSessionHas('success');
    $this->actingAs($this->owner)
        ->post("/admin/registration-forms/{$form->id}/registrations/{$registration->id}/approve")
        ->assertSessionHas('success');

    expect(Enrollment::query()->sole()->batch_id)->toBe($january->id);

    $this->batch->update(['capacity' => 40, 'seats_taken' => 0]);
    $this->actingAs($this->owner)
        ->put("/admin/registration-forms/{$form->id}/registrations/{$registration->id}/batch", ['batch_id' => $this->batch->id]);
    expect(Enrollment::query()->sole()->batch_id)->toBe($this->batch->id);

    // A batch of another course is not a choice.
    $other = Course::query()->create(['title' => 'Other', 'slug' => 'other-'.uniqid(), 'tagline' => 'x', 'summary' => 'x']);
    $otherBatch = Batch::query()->create(['course_id' => $other->id, 'name' => 'X', 'code' => 'X-'.uniqid(), 'status' => 'upcoming']);
    $this->actingAs($this->owner)
        ->put("/admin/registration-forms/{$form->id}/registrations/{$registration->id}/batch", ['batch_id' => $otherBatch->id])
        ->assertSessionHasErrors('batch_id');
});

it('lets staff who can view read registrations, but not decide them', function () {
    $form = ($this->makeForm)(['approval' => 'review']);
    $this->post($form->path(), ($this->entry)());
    $registration = Registration::query()->sole();
    $viewer = registrationStaff(['students.view']);

    $this->actingAs($viewer)->get("/admin/registration-forms/{$form->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/registrations/Show')
            ->where('canManage', false)
            ->where('table.rows.0.email', 'ritika@example.test')
            ->where('table.rows.0.answer_qlaptop', 'Yes')
            ->where('table.rows.0.details_college', 'Government Engineering College'));

    $this->actingAs($viewer)->post("/admin/registration-forms/{$form->id}/registrations/{$registration->id}/approve")->assertForbidden();
    $this->actingAs($viewer)->get('/admin/registration-forms/new')->assertForbidden();
    $this->actingAs(registrationStaff(['catalogue.view']))->get('/admin/registration-forms')->assertForbidden();
});

it('exports the registrations with every answer', function () {
    $form = ($this->makeForm)(['approval' => 'review']);
    $this->post($form->path(), ($this->entry)());

    $csv = $this->actingAs($this->owner)->get("/admin/registration-forms/{$form->id}?export=csv")->assertOk()->streamedContent();

    expect($csv)->toContain('Do you have a laptop?')->toContain('Government Engineering College')->toContain('ritika@example.test');
});

it('will not act on a registration through another form\'s address', function () {
    $form = ($this->makeForm)(['approval' => 'review']);
    $other = ($this->makeForm)();
    $this->post($form->path(), ($this->entry)());
    $registration = Registration::query()->sole();

    $this->actingAs($this->owner)
        ->post("/admin/registration-forms/{$other->id}/registrations/{$registration->id}/approve")
        ->assertNotFound();
});

/* -------------------------------------------------------- building forms */

it('builds a form, keeping question keys when a question is reworded', function () {
    $this->actingAs($this->owner)->post('/admin/registration-forms', [
        'title' => 'MIT Pune visit',
        'course_id' => $this->course->id,
        'batch_id' => $this->batch->id,
        'fee' => 'free',
        'approval' => 'review',
        'fields' => ['college', 'city'],
        'questions' => [
            ['label' => 'Year of study', 'type' => 'choice', 'required' => true, 'options' => ['Second', 'Third', '', 'Third']],
        ],
        'is_active' => true,
    ])->assertRedirect();

    $form = RegistrationForm::query()->sole();
    expect($form->slug)->toBe('mit-pune-visit')
        ->and($form->fields)->toBe(['college', 'city'])
        ->and($form->questions[0]['options'])->toBe(['Second', 'Third']);

    $key = $form->questions[0]['key'];

    $this->actingAs($this->owner)->put("/admin/registration-forms/{$form->id}", [
        'title' => 'MIT Pune visit',
        'slug' => 'mit-pune',
        'course_id' => $this->course->id,
        'fee' => 'free',
        'approval' => 'review',
        'questions' => [
            ['key' => 'made-up', 'label' => 'Anything else?', 'type' => 'long', 'required' => false],
            ['key' => $key, 'label' => 'Which year are you in?', 'type' => 'choice', 'required' => true, 'options' => ['Second', 'Third']],
        ],
    ])->assertRedirect();

    $form->refresh();
    expect($form->slug)->toBe('mit-pune')
        ->and($form->batch_id)->toBeNull()
        ->and($form->questions[1]['key'])->toBe($key)
        ->and($form->questions[0]['key'])->not->toBe('made-up');
});

it('refuses a choice question with fewer than two answers, a batch of another course and a close before the open', function () {
    $other = Course::query()->create(['title' => 'Other', 'slug' => 'other-'.uniqid(), 'tagline' => 'x', 'summary' => 'x']);
    $otherBatch = Batch::query()->create(['course_id' => $other->id, 'name' => 'X', 'code' => 'X-'.uniqid(), 'status' => 'upcoming']);

    $this->actingAs($this->owner)->post('/admin/registration-forms', [
        'title' => 'Bad',
        'course_id' => $this->course->id,
        'batch_id' => $otherBatch->id,
        'fee' => 'course',
        'approval' => 'auto',
    ])->assertSessionHasErrors('batch_id');

    $this->actingAs($this->owner)->post('/admin/registration-forms', [
        'title' => 'Bad',
        'course_id' => $this->course->id,
        'fee' => 'course',
        'approval' => 'auto',
        'opens_at' => '2026-12-10T10:00',
        'closes_at' => '2026-12-01T10:00',
        'questions' => [['label' => 'Pick', 'type' => 'choice', 'required' => true, 'options' => ['Only one']]],
    ])->assertSessionHasErrors(['closes_at']);

    $this->actingAs($this->owner)->post('/admin/registration-forms', [
        'title' => 'Bad',
        'course_id' => $this->course->id,
        'fee' => 'course',
        'approval' => 'auto',
        'questions' => [['label' => 'Pick', 'type' => 'choice', 'required' => true, 'options' => ['Only one']]],
    ])->assertSessionHasErrors(['questions.0.options']);

    expect(RegistrationForm::query()->count())->toBe(0);
});

it('keeps a form that has registrations: no deleting it and no changing its course', function () {
    $form = ($this->makeForm)();
    $this->post($form->path(), ($this->entry)());
    $other = Course::query()->create(['title' => 'Other', 'slug' => 'other-'.uniqid(), 'tagline' => 'x', 'summary' => 'x', 'is_published' => true]);

    $this->actingAs($this->owner)->delete("/admin/registration-forms/{$form->id}")->assertSessionHas('error');
    $this->actingAs($this->owner)->put("/admin/registration-forms/{$form->id}", [
        'title' => $form->title, 'slug' => $form->slug, 'course_id' => $other->id, 'fee' => 'course', 'approval' => 'auto',
    ])->assertSessionHasErrors('course_id');

    expect($form->fresh())->not->toBeNull()->course_id->toBe($this->course->id);
});

it('downloads a QR code that opens the form', function () {
    $form = ($this->makeForm)();

    $response = $this->actingAs($this->owner)->get("/admin/registration-forms/{$form->id}/qr")->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('image/svg+xml')
        ->and($response->getContent())->toContain('<svg');
});

it('opens every screen it adds', function () {
    $form = ($this->makeForm)();

    $this->actingAs($this->owner)->get('/admin/registration-forms')->assertOk()->assertInertia(fn ($page) => $page->has('table.rows', 1));
    $this->actingAs($this->owner)->get('/admin/registration-forms/new')->assertOk();
    $this->actingAs($this->owner)->get("/admin/registration-forms/{$form->id}/edit")->assertOk();
    $this->actingAs($this->owner)->get("/admin/registration-forms/{$form->id}")->assertOk();
});

/* -------------------------------------------------------- human check */

it('asks Cloudflare when the human check is switched on', function () {
    config(['services.turnstile.site_key' => 'site', 'services.turnstile.secret' => 'secret']);
    $form = ($this->makeForm)();

    $this->get($form->path())->assertInertia(fn ($page) => $page->where('humanCheck', 'site'));

    $this->post($form->path(), ($this->entry)())->assertSessionHasErrors('human');

    Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);
    $this->post($form->path(), ($this->entry)(['cf-turnstile-response' => 'bad']))->assertSessionHasErrors('human');

    $this->post($form->path(), ($this->entry)(['cf-turnstile-response' => 'good']))->assertSessionHas('registrationSent', true);

    expect(Registration::query()->count())->toBe(1);
});

/* ------------------------------------------------- the student's fee link */

it('sends a student asked for a fee to their own payments page, not the client portal', function () {
    $student = User::factory()->student()->create();
    $enrolment = app(Enroller::class)->enrol($student, $this->course, $this->batch);

    Notification::assertSentTo($student, PaymentRequested::class, function ($notification) use ($student, $enrolment) {
        return $notification->toMail($student)->actionUrl === url("/student/payments/{$enrolment->payment_request_id}")
            && $notification->toArray($student)['url'] === "/student/payments/{$enrolment->payment_request_id}";
    });
});
