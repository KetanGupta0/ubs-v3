<?php

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\Refund;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Support\LegacyDatabase;

beforeEach(function () {
    LegacyDatabase::create();
    LegacyDatabase::createDelivery();
    LegacyDatabase::createPayments();
    Storage::fake('local');

    $this->student = LegacyDatabase::user();
    $this->admin = LegacyDatabase::user(['role' => 'admin', 'email' => 'office@example.test']);
    $program = LegacyDatabase::insert('training_programs', ['title' => 'Data course', 'slug' => 'data-course', 'price' => '4999.00']);
    $batch = LegacyDatabase::insert('batches', ['program_id' => $program, 'name' => 'June']);
    $this->enrolment = LegacyDatabase::insert('enrollments', ['student_id' => $this->student, 'program_id' => $program, 'batch_id' => $batch, 'status' => 'active', 'student_name' => 'x', 'student_email' => 'x']);
});

function importPayments($test): array
{
    $test->artisan('legacy:import', ['--all-generation-one' => true])->assertSuccessful();
    $files = Storage::disk('local')->files('legacy-reports');
    sort($files);

    return json_decode(Storage::disk('local')->get(end($files)), true);
}

it('brings a Razorpay payment across in paise, and opens the course it paid for', function () {
    LegacyDatabase::insert('payments', ['student_id' => $this->student, 'enrollment_id' => $this->enrolment, 'amount' => '4999.99', 'method' => 'razorpay',
        'razorpay_order_id' => 'order_A1', 'razorpay_payment_id' => 'pay_A1', 'status' => 'paid', 'created_at' => '2026-06-01 10:00:00', 'updated_at' => '2026-06-01 10:02:00']);

    importPayments($this);

    $transaction = Transaction::query()->sole();
    expect($transaction->amount)->toBe(499999)
        ->and($transaction->status)->toBe('successful')
        ->and($transaction->gateway)->toBe('razorpay')
        ->and($transaction->gateway_payment_id)->toBe('pay_A1')
        ->and($transaction->paid_at->toDateTimeString())->toBe('2026-06-01 10:02:00')
        ->and(Enrollment::query()->sole()->has_paid)->toBeTrue()
        ->and(Invoice::query()->count())->toBe(0);
});

it('records a payment taken by hand as offline, with who recorded it in the audit log', function () {
    LegacyDatabase::insert('payments', ['student_id' => $this->student, 'amount' => '2000', 'method' => 'cash', 'status' => 'paid', 'recorded_by' => $this->admin, 'notes' => 'Paid at the front desk']);

    importPayments($this);

    $transaction = Transaction::query()->sole();
    expect($transaction->gateway)->toBe('offline')
        ->and($transaction->method)->toBe('cash')
        ->and(AuditLog::query()->where('action', 'payment.imported_notes')->sole()->changes['notes']['to'])->toBe('Paid at the front desk');
});

it('keeps a refund as a refund, and a failed or pending payment as what it was', function () {
    LegacyDatabase::insert('payments', ['student_id' => $this->student, 'amount' => '1500', 'status' => 'refunded', 'razorpay_payment_id' => 'pay_R']);
    LegacyDatabase::insert('payments', ['student_id' => $this->student, 'amount' => '1500', 'status' => 'failed', 'razorpay_payment_id' => 'pay_F']);
    LegacyDatabase::insert('payments', ['student_id' => $this->student, 'enrollment_id' => $this->enrolment, 'amount' => '1500', 'status' => 'pending']);

    importPayments($this);

    expect(Transaction::query()->where('status', 'refunded')->sole()->amount)->toBe(150000)
        ->and(Refund::query()->sole()->amount)->toBe(150000)
        ->and(Transaction::query()->where('status', 'failed')->count())->toBe(1)
        ->and(Transaction::query()->where('status', 'pending')->count())->toBe(1)
        // A pending payment does not open the course.
        ->and(Enrollment::query()->sole()->has_paid)->toBeFalse();
});

it('never brings the same Razorpay payment in twice', function () {
    LegacyDatabase::insert('payments', ['student_id' => $this->student, 'amount' => '100', 'status' => 'paid', 'razorpay_payment_id' => 'pay_SAME']);
    $second = LegacyDatabase::insert('payments', ['student_id' => $this->student, 'amount' => '100', 'status' => 'paid', 'razorpay_payment_id' => 'pay_SAME']);

    $report = importPayments($this);
    importPayments($this);

    expect(Transaction::query()->where('gateway_payment_id', 'pay_SAME')->count())->toBe(1)
        ->and(collect($report['steps']['payments']['warnings'])->where('code', 'duplicate_gateway_id')->pluck('source')->all())->toBe(["payments#{$second}"]);
});

it('shows a fee paid on the old site as paid, for what was paid, and links it once', function () {
    $owner = User::factory()->owner()->create();
    LegacyDatabase::insert('payments', ['student_id' => $this->student, 'enrollment_id' => $this->enrolment, 'amount' => '3500.00', 'method' => 'upi', 'status' => 'paid', 'updated_at' => '2026-06-02 09:00:00']);
    LegacyDatabase::insert('payments', ['student_id' => $this->student, 'enrollment_id' => $this->enrolment, 'amount' => '1000.00', 'method' => 'razorpay', 'razorpay_payment_id' => 'pay_F1', 'status' => 'failed']);

    importPayments($this);
    importPayments($this);

    $enrolment = Enrollment::query()->sole();
    $request = PaymentRequest::query()->sole();

    expect($enrolment->has_paid)->toBeTrue()
        ->and($enrolment->payment_request_id)->toBe($request->id)
        ->and($request->status)->toBe('paid')
        ->and($request->total)->toBe(350000)
        ->and($request->tax)->toBe(0)
        ->and($request->reference)->toStartWith('V2-FEE-')
        ->and($request->payable_id)->toBe($enrolment->id)
        ->and(Transaction::query()->where('status', 'successful')->sole()->payment_request_id)->toBe($request->id)
        ->and(Transaction::query()->where('status', 'failed')->sole()->payment_request_id)->toBeNull()
        ->and(Invoice::query()->count())->toBe(0);

    // The enrolments list says paid, never free.
    $this->actingAs($owner)->get('/admin/enrolments')
        ->assertInertia(fn ($page) => $page->where('table.rows.0.fee', 'Paid'));
});
