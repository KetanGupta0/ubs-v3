<?php

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Services\Chat\Rooms;
use App\Services\Lms\Enroller;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(PermissionSeeder::class);

    Setting::put('company.state', 'Madhya Pradesh', 'company');
    Setting::put('invoicing.prefix', 'UBS', 'invoicing');

    $this->owner = User::factory()->owner()->create();
    $this->student = User::factory()->student()->create(['name' => 'Asha Verma']);

    $this->course = Course::query()->create([
        'title' => 'Full stack internship',
        'slug' => 'manage-course-'.uniqid(),
        'type' => 'internship',
        'tagline' => 'Build something real',
        'summary' => 'Eight weeks, live.',
        'price' => Money::toPaise(12000),
        'is_published' => true,
    ]);

    $this->batchFor = fn (string $name, ?int $capacity = 30) => Batch::query()->create([
        'course_id' => $this->course->id,
        'name' => $name,
        'code' => 'MG-'.uniqid(),
        'starts_on' => today()->addWeek(),
        'capacity' => $capacity,
        'status' => 'upcoming',
        'is_published' => true,
    ]);

    $this->september = ($this->batchFor)('September batch');
});

function staffWith(array $keys): User
{
    $staff = User::factory()->admin()->create();
    $staff->permissions()->attach(Permission::query()->whereIn('key', $keys)->get());

    return $staff->fresh();
}

it('lists every enrolment, filtered by course and by whether the fee is paid', function () {
    $paid = app(Enroller::class)->enrol($this->student, $this->course, $this->september, waiveFee: true, notify: false);
    $unpaid = app(Enroller::class)->enrol(User::factory()->student()->create(['name' => 'Imran Qureshi']), $this->course, $this->september, notify: false);

    $this->actingAs($this->owner)
        ->get("/admin/enrolments?filter[course]={$this->course->id}&filter[fee]=unpaid")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/enrolments/Index')
            ->has('table.rows', 1)
            ->where('table.rows.0.id', $unpaid->id)
            ->where('table.rows.0.student', 'Imran Qureshi')
            ->where('table.rows.0.fee', Money::display($unpaid->paymentRequest->invoice->total).' due')
            ->where('counts.unpaid', 1)
            ->missing('studentOptions'));

    expect($paid->has_paid)->toBeTrue();
});

it('loads the student list only when it is asked for', function () {
    $this->actingAs($this->owner)
        ->get('/admin/enrolments')
        ->assertInertia(fn ($page) => $page
            ->missing('studentOptions')
            ->reloadOnly('studentOptions', fn ($reload) => $reload
                ->has('studentOptions', 1)
                ->where('studentOptions.0.label', 'Asha Verma')));
});

it('enrols a student by hand and raises the fee', function () {
    $this->actingAs($this->owner)
        ->from('/admin/enrolments')
        ->post('/admin/enrolments', [
            'student_id' => $this->student->id,
            'course_id' => $this->course->id,
            'batch_id' => $this->september->id,
        ])
        ->assertRedirect('/admin/enrolments')
        ->assertSessionHas('success', 'Asha Verma is enrolled on Full stack internship. The fee has been raised and the invoice sent.');

    $enrolment = Enrollment::query()->sole();

    expect($enrolment->source)->toBe('admin')
        ->and($enrolment->has_paid)->toBeFalse()
        ->and($enrolment->payment_request_id)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'enrolment.created')->exists())->toBeTrue();
});

it('will not put somebody on a batch twice, or on a batch of another course', function () {
    app(Enroller::class)->enrol($this->student, $this->course, $this->september, waiveFee: true, notify: false);

    $this->actingAs($this->owner)->post('/admin/enrolments', [
        'student_id' => $this->student->id,
        'course_id' => $this->course->id,
        'batch_id' => $this->september->id,
    ])->assertSessionHasErrors(['student_id' => 'Asha Verma is already on that batch.']);

    $other = Course::query()->create([
        'title' => 'Python', 'slug' => 'py-'.uniqid(), 'type' => 'course',
        'tagline' => 'x', 'summary' => 'y', 'price' => 0, 'is_published' => true,
    ]);

    $this->actingAs($this->owner)->post('/admin/enrolments', [
        'student_id' => $this->student->id,
        'course_id' => $other->id,
        'batch_id' => $this->september->id,
    ])->assertSessionHasErrors(['batch_id' => 'That batch belongs to a different course.']);
});

it('refuses a full batch unless told to go over capacity', function () {
    $tiny = ($this->batchFor)('Tiny batch', 1);
    app(Enroller::class)->enrol(User::factory()->student()->create(), $this->course, $tiny, waiveFee: true, notify: false);

    $payload = ['student_id' => $this->student->id, 'course_id' => $this->course->id, 'batch_id' => $tiny->id, 'waive_fee' => true];

    $this->actingAs($this->owner)->post('/admin/enrolments', $payload)->assertSessionHasErrors('batch_id');

    $this->actingAs($this->owner)
        ->post('/admin/enrolments', [...$payload, 'over_capacity' => true])
        ->assertSessionHasNoErrors();

    expect($tiny->fresh()->seats_taken)->toBe(2);
});

it('moves a student to another batch of the same course, seats and group chat included', function () {
    $october = ($this->batchFor)('October batch');
    $enrolment = app(Enroller::class)->enrol($this->student, $this->course, $this->september, waiveFee: true, notify: false);

    // The September group already exists, with the student in it.
    app(Rooms::class)->forBatch($this->september);
    expect(ConversationParticipant::query()->where('user_id', $this->student->id)->whereNull('left_at')->count())->toBe(1);

    $this->actingAs($this->owner)
        ->put("/admin/enrolments/{$enrolment->id}/batch", ['batch_id' => $october->id])
        ->assertSessionHas('success', 'Moved to October batch.');

    expect($enrolment->fresh()->batch_id)->toBe($october->id)
        ->and($this->september->fresh()->seats_taken)->toBe(0)
        ->and($october->fresh()->seats_taken)->toBe(1);

    $groupOf = fn (Batch $batch) => Conversation::query()->where('type', 'batch_group')->where('batch_id', $batch->id)->sole();
    $membership = fn (Batch $batch) => ConversationParticipant::query()
        ->where('conversation_id', $groupOf($batch)->id)
        ->where('user_id', $this->student->id)
        ->first();

    expect($membership($october)?->left_at)->toBeNull()
        ->and($membership($this->september)->left_at)->not->toBeNull();

    expect(AuditLog::query()->where('action', 'enrolment.moved')->sole()->changes['batch'])
        ->toBe(['from' => 'September batch', 'to' => 'October batch']);
});

it('will not move a student to a batch of a different course', function () {
    $other = Course::query()->create([
        'title' => 'Data', 'slug' => 'data-'.uniqid(), 'type' => 'course',
        'tagline' => 'x', 'summary' => 'y', 'price' => 0, 'is_published' => true,
    ]);
    $elsewhere = Batch::query()->create([
        'course_id' => $other->id, 'name' => 'Data batch', 'code' => 'DB-'.uniqid(),
        'starts_on' => today(), 'status' => 'upcoming', 'is_published' => true,
    ]);
    $enrolment = app(Enroller::class)->enrol($this->student, $this->course, $this->september, waiveFee: true, notify: false);

    $this->actingAs($this->owner)
        ->put("/admin/enrolments/{$enrolment->id}/batch", ['batch_id' => $elsewhere->id])
        ->assertSessionHasErrors('batch_id');

    expect($enrolment->fresh()->batch_id)->toBe($this->september->id);
});

it('changes status, giving the seat back when somebody is dropped', function () {
    $enrolment = app(Enroller::class)->enrol($this->student, $this->course, $this->september, waiveFee: true, notify: false);

    $this->actingAs($this->owner)->put("/admin/enrolments/{$enrolment->id}/status", ['status' => 'on_hold'])
        ->assertSessionHas('success', 'Enrolment is now on hold.');
    $this->actingAs($this->owner)->put("/admin/enrolments/{$enrolment->id}/status", ['status' => 'dropped']);

    expect($enrolment->fresh()->status)->toBe('dropped')
        ->and($enrolment->fresh()->dropped_at)->not->toBeNull()
        ->and($this->september->fresh()->seats_taken)->toBe(0);

    $this->actingAs($this->owner)->put("/admin/enrolments/{$enrolment->id}/status", ['status' => 'active']);

    // Back on the batch, and the record no longer claims they dropped.
    expect($enrolment->fresh()->dropped_at)->toBeNull()
        ->and($this->september->fresh()->seats_taken)->toBe(1);
});

it('waives a fee: the invoice is cancelled, the course opens, and the reason is kept', function () {
    $enrolment = app(Enroller::class)->enrol($this->student, $this->course, $this->september, notify: false);

    $this->actingAs($this->owner)
        ->post("/admin/enrolments/{$enrolment->id}/waive", ['reason' => 'Scholarship agreed with the college'])
        ->assertSessionHas('success');

    $enrolment->refresh();

    expect($enrolment->has_paid)->toBeTrue()
        ->and($enrolment->paymentRequest->status)->toBe('cancelled')
        ->and($enrolment->paymentRequest->invoice->status)->toBe('cancelled')
        ->and(AuditLog::query()->where('action', 'enrolment.fee_waived')->sole()->changes['reason'])
        ->toBe('Scholarship agreed with the college');
});

it('will not waive a fee that has been partly paid', function () {
    $enrolment = app(Enroller::class)->enrol($this->student, $this->course, $this->september, notify: false);
    $invoice = $enrolment->paymentRequest->invoice;

    $this->actingAs($this->owner)->post("/admin/billing/{$enrolment->payment_request_id}/offline", [
        'amount' => 1000,
        'method' => 'upi',
    ]);

    $this->actingAs($this->owner)
        ->post("/admin/enrolments/{$enrolment->id}/waive", ['reason' => 'Changed our minds'])
        ->assertSessionHas('error');

    expect($enrolment->fresh()->has_paid)->toBeFalse()
        ->and($invoice->fresh()->status)->toBe('partially_paid');
});

it('opens the course when the fee is paid by bank transfer, not only online', function () {
    $enrolment = app(Enroller::class)->enrol($this->student, $this->course, $this->september, notify: false);
    /** @var Invoice $invoice */
    $invoice = $enrolment->paymentRequest->invoice;

    // Part of it first: the course stays as it is until the fee is settled.
    $this->actingAs($this->owner)->post("/admin/billing/{$enrolment->payment_request_id}/offline", [
        'amount' => 5000,
        'method' => 'bank transfer',
    ]);
    expect($enrolment->fresh()->has_paid)->toBeFalse();

    $this->actingAs($this->owner)->post("/admin/billing/{$enrolment->payment_request_id}/offline", [
        'amount' => $invoice->fresh()->balance() / 100,
        'method' => 'bank transfer',
        'note' => 'UTR 1234',
    ])->assertSessionHas('success');

    expect($enrolment->fresh()->has_paid)->toBeTrue();
});

it('lets a trainer see enrolments but not change them', function () {
    $enrolment = app(Enroller::class)->enrol($this->student, $this->course, $this->september, waiveFee: true, notify: false);
    $trainer = staffWith(['students.view']);

    $this->actingAs($trainer)
        ->get('/admin/enrolments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManage', false)->where('canTakePayments', false));

    $this->actingAs($trainer)->put("/admin/enrolments/{$enrolment->id}/status", ['status' => 'dropped'])->assertForbidden();
    $this->actingAs($trainer)->post('/admin/enrolments', [])->assertForbidden();

    $this->actingAs(staffWith(['leads.view']))->get('/admin/enrolments')->assertForbidden();
});
