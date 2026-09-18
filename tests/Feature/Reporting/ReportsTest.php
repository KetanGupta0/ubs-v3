<?php

use App\Enums\Role;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Billing\Invoicer;
use App\Services\Reports\BatchPerformanceReport;
use App\Services\Reports\EnrolmentFunnelReport;
use App\Services\Reports\ReceivablesReport;
use App\Services\Reports\ReportWindow;
use App\Services\Reports\RevenueReport;
use App\Support\Money;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);

    Setting::put('company.state', 'Madhya Pradesh', 'company');
    Setting::put('invoicing.prefix', 'UBS', 'invoicing');

    $this->owner = User::factory()->owner()->create();
    $this->client = User::factory()->client()->create();
    $this->client->profile()->create(['state' => 'Madhya Pradesh']);
});

function invoiceFor(User $client, int $rupees, ?string $issuedAt = null, ?string $dueOn = null): Invoice
{
    $request = PaymentRequest::query()->create([
        'user_id' => $client->id,
        'title' => 'Milestone',
        'due_on' => $dueOn ?? today()->addDays(7),
    ]);

    $request->price(Money::toPaise($rupees), 18)->save();

    $invoice = app(Invoicer::class)->issueFor($request);

    if ($issuedAt) {
        $invoice->forceFill(['issued_at' => $issuedAt])->save();
    }

    if ($dueOn) {
        $invoice->forceFill(['due_on' => $dueOn])->save();
    }

    return $invoice->fresh();
}

function window(string $from = '-11 months', string $to = 'now'): ReportWindow
{
    return new ReportWindow(now()->parse($from)->startOfDay(), now()->parse($to)->endOfDay());
}

/* -------------------------------------------------------------- revenue */

it('counts what was invoiced and what arrived', function () {
    $invoice = invoiceFor($this->client, 100000);

    Transaction::query()->create([
        'user_id' => $this->client->id,
        'invoice_id' => $invoice->id,
        'amount' => Money::toPaise(50000),
        'status' => 'successful',
        'gateway' => 'manual',
    ]);

    $payload = app(RevenueReport::class)->run(window());

    expect($payload['summary'][0]['value'])->toBe(Money::display($invoice->total))
        ->and($payload['summary'][1]['value'])->toBe(Money::display(Money::toPaise(50000)));
});

it('leaves a cancelled invoice out of revenue', function () {
    $invoice = invoiceFor($this->client, 100000);
    $invoice->forceFill(['status' => 'cancelled'])->save();

    $payload = app(RevenueReport::class)->run(window());

    expect($payload['summary'][0]['value'])->toBe('₹0.00');
});

it('gives a row per month in the window', function () {
    $payload = app(RevenueReport::class)->run(window('-2 months'));

    expect($payload['rowCount'])->toBe(3)
        ->and($payload['charts'][0]['categories'])->toHaveCount(3);
});

/* ---------------------------------------------------------- receivables */

it('ages what is owed from the due date', function () {
    invoiceFor($this->client, 50000, null, today()->subDays(45)->toDateString());
    invoiceFor($this->client, 20000, null, today()->addDays(10)->toDateString());

    $payload = app(ReceivablesReport::class)->run(window());

    $buckets = collect($payload['charts'][0]['categories'])
        ->combine($payload['charts'][0]['series'][0]['values']);

    expect($buckets['31 to 60 days'])->toBeGreaterThan(0)
        ->and($buckets['Not yet due'])->toBeGreaterThan(0)
        ->and($buckets['Over 90 days'])->toBe(0.0);
});

it('keeps old debt even when it falls outside the window', function () {
    // Invoiced two years ago, still owed today. A receivables report that
    // hides it because of a date range is worse than no report.
    invoiceFor($this->client, 90000, now()->subYears(2)->toDateTimeString(), today()->subYears(2)->toDateString());

    $payload = app(ReceivablesReport::class)->run(window('-1 month'));

    expect($payload['rowCount'])->toBe(1);
});

/* ------------------------------------------------------- the screens */

it('opens every report the owner can reach', function () {
    $keys = ['revenue', 'receivables', 'project-health', 'batch-performance', 'attendance', 'enrolment-funnel', 'certificates'];

    foreach ($keys as $key) {
        $this->actingAs($this->owner)
            ->get("/admin/reports/{$key}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/reports/Show')->where('report.key', $key));
    }
});

it('offers a member of staff only the reports their permissions reach', function () {
    $staff = User::factory()->create(['role' => Role::Admin]);
    $staff->permissions()->sync(Permission::query()->where('key', 'students.view')->pluck('id'));

    $props = $this->actingAs($staff)->get('/admin/reports')->inertiaProps();
    $keys = collect($props['reports'])->pluck('key');

    expect($keys)->toContain('attendance')
        ->and($keys)->not->toContain('revenue');

    // And the one they cannot see is not merely hidden.
    $this->actingAs($staff)->get('/admin/reports/revenue')->assertNotFound();
});

it('keeps a client out of the report library', function () {
    $this->actingAs($this->client)->get('/admin/reports')->assertRedirect('/client');
});

/* --------------------------------------------------------- downloads */

it('downloads the same period the screen is showing', function () {
    invoiceFor($this->client, 100000);

    $response = $this->actingAs($this->owner)->get('/admin/reports/revenue?export=csv&from='
        .today()->subMonth()->toDateString().'&to='.today()->toDateString());

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv');
});

it('refuses a format it does not produce', function () {
    $this->actingAs($this->owner)->get('/admin/reports/revenue?export=docx')->assertStatus(400);
});

it('turns a reversed date range the right way round', function () {
    $request = Request::create('/', 'GET', ['from' => '2026-06-30', 'to' => '2026-01-01']);

    $window = ReportWindow::fromRequest($request);

    expect($window->from->toDateString())->toBe('2026-01-01')
        ->and($window->to->toDateString())->toBe('2026-06-30');
});

/* ------------------------------------------------- batch performance */

it('reads the same result card the student sees', function () {
    $course = Course::query()->create([
        'title' => 'Web development internship',
        'slug' => 'report-course-'.uniqid(),
        'type' => 'internship',
        'tagline' => 'Build something real',
        'summary' => 'Eight weeks, live.',
        'pass_percent' => 50,
    ]);

    $batch = Batch::query()->create([
        'course_id' => $course->id,
        'name' => 'September batch',
        'code' => 'RP-'.uniqid(),
        'starts_on' => today()->subWeeks(4),
    ]);

    Enrollment::query()->create([
        'user_id' => User::factory()->student()->create()->id,
        'course_id' => $course->id,
        'batch_id' => $batch->id,
        'enrolled_at' => now()->subWeeks(4),
        'has_paid' => true,
    ]);

    $payload = app(BatchPerformanceReport::class)->run(window());

    expect($payload['rowCount'])->toBe(1)
        ->and($payload['rows'][0]['batch'])->toBe('September batch')
        ->and($payload['rows'][0]['studentsValue'])->toBe(1);
});

/* --------------------------------------------------------------- funnel */

it('does not report a share of a stage nobody reached', function () {
    // A college sending a batch straight in, with no enquiries recorded, is a
    // real situation. "500% of enquiries" is not what it means.
    $course = Course::query()->create([
        'title' => 'Web development internship',
        'slug' => 'funnel-course-'.uniqid(),
        'type' => 'internship',
        'tagline' => 'Build something real',
        'summary' => 'Eight weeks, live.',
    ]);

    foreach (range(1, 3) as $ignored) {
        Enrollment::query()->create([
            'user_id' => User::factory()->student()->create()->id,
            'course_id' => $course->id,
            'enrolled_at' => now(),
            'has_paid' => true,
        ]);
    }

    $payload = app(EnrolmentFunnelReport::class)->run(window());

    $enrolled = collect($payload['rows'])->firstWhere('stage', 'Enrolled');

    expect($enrolled['people'])->toBe('3')
        ->and($enrolled['ofStart'])->toBe('—')
        ->and($enrolled['ofPrevious'])->toBe('—');
});
