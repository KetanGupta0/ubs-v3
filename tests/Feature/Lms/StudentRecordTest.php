<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Services\Lms\Credentials;
use App\Services\Lms\Enroller;
use App\Services\Lms\Warnings;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(PermissionSeeder::class);
    Setting::put('company.state', 'Madhya Pradesh', 'company');
    Setting::put('invoicing.prefix', 'UBS', 'invoicing');

    $this->owner = User::factory()->owner()->create();
    $this->student = User::factory()->student()->create(['name' => 'Neha Pillai']);

    $course = fn (string $title, int $rupees) => Course::query()->create([
        'title' => $title, 'slug' => str($title)->slug().'-'.uniqid(), 'type' => 'course',
        'tagline' => 'x', 'summary' => 'y', 'price' => Money::toPaise($rupees), 'is_published' => true,
    ]);

    $this->paid = $course('Vue.js in depth', 8000);
    $this->free = $course('Git basics', 0);

    $this->batch = Batch::query()->create([
        'course_id' => $this->paid->id, 'name' => 'Vue November', 'code' => 'SR-'.uniqid(),
        'starts_on' => today()->addWeek(), 'status' => 'upcoming', 'is_published' => true,
    ]);

    $enroller = app(Enroller::class);
    $this->unpaidEnrolment = $enroller->enrol($this->student, $this->paid, $this->batch, notify: false);
    $this->freeEnrolment = $enroller->enrol($this->student, $this->free, notify: false);

    app(Credentials::class)->issueCertificate($this->freeEnrolment->fresh(), $this->owner, force: true);
    app(Warnings::class)->issue($this->student, 'Missed two classes in a row', $this->batch, issuedBy: $this->owner, privateNote: 'Family emergency, go gently');
});

it('shows a student their whole learning record on the admin profile', function () {
    $this->actingAs($this->owner)
        ->get("/admin/students/{$this->student->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/people/Show')
            ->where('canSeeBilling', true)
            ->has('record.enrolments', 2)
            ->where('record.counts.active', 2)
            ->where('record.counts.unpaid', 1)
            ->where('record.counts.openWarnings', 1)
            ->where('record.enrolments', fn ($rows) => collect($rows)->firstWhere('course', 'Vue.js in depth')['fee'] !== 'Paid'
                && collect($rows)->firstWhere('course', 'Git basics')['fee'] === 'Free')
            ->has('record.payments', 1)
            ->where('record.payments.0.status', 'pending')
            ->has('record.certificates', 1)
            ->where('record.certificates.0.title', fn ($title) => str_contains($title, 'Git basics'))
            ->where('record.warnings.0.reason', 'Missed two classes in a row')
            ->where('record.warnings.0.privateNote', 'Family emergency, go gently'));
});

it('uses the same result arithmetic as the student sees', function () {
    $this->actingAs($this->owner)
        ->get("/admin/students/{$this->student->id}")
        ->assertInertia(fn ($page) => $page
            ->has('record.enrolments.0.result', fn ($result) => $result
                ->hasAll(['overall', 'grade', 'passing', 'passMark', 'shortOnAttendance', 'attendance', 'attendanceFloor', 'quizzes', 'assignments'])));
});

it('keeps payments out of the record for staff who cannot see billing', function () {
    $trainer = User::factory()->admin()->create();
    $trainer->permissions()->attach(Permission::query()->where('key', 'students.view')->sole());

    $this->actingAs($trainer->fresh())
        ->get("/admin/students/{$this->student->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canSeeBilling', false)
            // Left out on the server, not merely hidden by the page.
            ->has('record.payments', 0)
            ->has('record.enrolments', 2));
});

it('gives a client profile no learning record', function () {
    $client = User::factory()->client()->create();

    $this->actingAs($this->owner)
        ->get("/admin/clients/{$client->id}")
        ->assertInertia(fn ($page) => $page->where('record', null));
});

it('never sends the private note of a warning to the student', function () {
    $this->actingAs($this->student)
        ->get('/student/warnings')
        ->assertOk()
        ->assertDontSee('Family emergency, go gently');
});
