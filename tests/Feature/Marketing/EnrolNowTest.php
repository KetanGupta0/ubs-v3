<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\PaymentRequest;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Setting::put('company.state', 'Madhya Pradesh', 'company');
    Setting::put('invoicing.prefix', 'UBS', 'invoicing');

    $this->course = Course::query()->create([
        'title' => 'Laravel for working developers', 'slug' => 'laravel-working-devs', 'type' => 'course',
        'tagline' => 'x', 'summary' => 'y', 'price' => Money::toPaise(25000),
        'visibility' => 'public', 'is_published' => true,
    ]);
    $this->batch = Batch::query()->create([
        'course_id' => $this->course->id, 'name' => 'January batch', 'code' => 'EN-'.uniqid(),
        'starts_on' => today()->addMonth(), 'status' => 'upcoming', 'is_published' => true,
    ]);

    $this->confirm = "/student/catalogue?join={$this->course->id}&batch={$this->batch->id}";
});

it('sends a visitor to register, remembering the course and batch they chose', function () {
    $this->get("/enrol/laravel-working-devs?batch={$this->batch->id}")
        ->assertRedirect('/register')
        ->assertSessionHas('url.intended', url($this->confirm))
        ->assertSessionHas('enrol.course', 'Laravel for working developers');

    $this->get('/register')->assertInertia(fn ($page) => $page->where('enrolling', 'Laravel for working developers'));
});

it('takes a new student from registering straight to confirming the course', function () {
    $this->get("/enrol/laravel-working-devs?batch={$this->batch->id}");

    $this->post('/register', [
        'name' => 'Rahul Verma', 'email' => 'rahul@example.com', 'mobile' => '98765 43210',
        'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'terms' => true,
    ])->assertRedirect($this->confirm);

    $this->get($this->confirm)->assertInertia(fn ($page) => $page
        ->where('join.courseId', $this->course->id)
        ->where('join.batchId', $this->batch->id));

    // Confirming enrols them and takes them to pay, the fee priced from the course.
    $this->post('/student/enrol', ['course_id' => $this->course->id, 'batch_id' => $this->batch->id])
        ->assertRedirect();

    $student = User::query()->where('email', 'rahul@example.com')->sole();
    $request = PaymentRequest::query()->sole();

    expect(Enrollment::query()->where('user_id', $student->id)->sole()->has_paid)->toBeFalse()
        ->and($request->total)->toBeLessThanOrEqual(Money::toPaise(25000));

    // The banner does not follow them around after they have registered.
    expect(session('enrol.course'))->toBeNull();
});

it('takes somebody with an account through sign in to the same step', function () {
    User::factory()->student()->create(['email' => 'aarti@example.com', 'password' => 'Password123!']);

    $this->get("/enrol/laravel-working-devs?batch={$this->batch->id}");

    $this->post('/login', ['identifier' => 'aarti@example.com', 'password' => 'Password123!'])
        ->assertRedirect($this->confirm);
});

it('sends a signed in student straight to the confirm step', function () {
    $this->actingAs(User::factory()->student()->create())
        ->get('/enrol/laravel-working-devs')
        ->assertRedirect("/student/catalogue?join={$this->course->id}");
});

it('does not let a client or a member of staff enrol from the public page', function () {
    $this->actingAs(User::factory()->client()->create())
        ->get('/enrol/laravel-working-devs')
        ->assertRedirect('/training/laravel-working-devs');
});

it('will not start enrolment on a course the public site does not list', function () {
    $this->course->forceFill(['visibility' => 'lms_only'])->save();
    $this->get('/enrol/laravel-working-devs')->assertNotFound();

    $this->course->forceFill(['visibility' => 'public', 'is_published' => false])->save();
    $this->get('/enrol/laravel-working-devs')->assertNotFound();
});

it('sends people back to the course page while enrolment is closed', function () {
    $this->course->forceFill(['is_enrollment_open' => false])->save();

    $this->get('/enrol/laravel-working-devs')
        ->assertRedirect('/training/laravel-working-devs')
        ->assertSessionMissing('url.intended');
});

it('ignores a batch that belongs to a different course', function () {
    $other = Course::query()->create([
        'title' => 'Other', 'slug' => 'other-course', 'type' => 'course', 'tagline' => 'x', 'summary' => 'y',
        'visibility' => 'public', 'is_published' => true,
    ]);
    $elsewhere = Batch::query()->create(['course_id' => $other->id, 'name' => 'Elsewhere', 'code' => 'EL-'.uniqid(), 'starts_on' => today(), 'is_published' => true]);

    $this->actingAs(User::factory()->student()->create())
        ->get("/enrol/laravel-working-devs?batch={$elsewhere->id}")
        ->assertRedirect("/student/catalogue?join={$this->course->id}");
});
