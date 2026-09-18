<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Billing\Invoicer;
use App\Services\Lms\Credentials;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * Enough history for the reports to have a shape, in development.
 *
 * A revenue chart with one point is not a chart, and a receivables report with
 * nothing overdue proves nothing about the ageing. This lays down nine months
 * of ordinary trading for the demo client: most invoices paid, one left to run
 * late, and a couple of certificates issued.
 *
 * Every figure here is invented and obviously so. It exists to exercise the
 * screens, never to appear anywhere a real number is expected.
 */
class ReportingSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Worked examples are never seeded in production.');

            return;
        }

        $client = User::query()->role(Role::Client)->first();
        $admin = User::query()->role(Role::Admin)->first();

        if (! $client) {
            $this->command?->warn('No client account, so there is no trading history to lay down.');

            return;
        }

        $this->seedTrading($client);
        $this->seedCertificate($admin);

        $this->command?->info('Seeded nine months of trading history for the reports.');
    }

    protected function seedTrading(User $client): void
    {
        $invoicer = app(Invoicer::class);

        // Roughly upward, with a quiet August, because a perfectly smooth line
        // is the one shape real trading never makes.
        $months = [
            9 => 185000, 8 => 240000, 7 => 210000, 6 => 320000,
            5 => 295000, 4 => 410000, 3 => 155000, 2 => 380000, 1 => 425000,
        ];

        foreach ($months as $ago => $rupees) {
            $raisedOn = now()->subMonths($ago)->startOfMonth()->addDays(4);

            $reference = 'Delivery for '.$raisedOn->format('F Y');

            if (PaymentRequest::query()->where('title', $reference)->exists()) {
                continue;
            }

            $request = PaymentRequest::query()->create([
                'user_id' => $client->id,
                'title' => $reference,
                'description' => 'Work delivered in '.$raisedOn->format('F').'.',
                'due_on' => $raisedOn->copy()->addDays(14),
            ]);

            $request->price(Money::toPaise($rupees), 18)->save();
            $request->forceFill(['created_at' => $raisedOn, 'updated_at' => $raisedOn])->save();

            $invoice = $invoicer->issueFor($request);
            $invoice->forceFill([
                'issued_at' => $raisedOn,
                'created_at' => $raisedOn,
                'due_on' => $raisedOn->copy()->addDays(14),
            ])->save();

            // The two oldest stay unpaid, so the ageing buckets have something
            // in the far end and somebody has a reason to open the report.
            if ($ago <= 7) {
                $this->settle($client, $invoice, $raisedOn->copy()->addDays(rand(3, 20)));
            }
        }
    }

    protected function settle(User $client, Invoice $invoice, $paidOn): void
    {
        $transaction = Transaction::query()->create([
            'user_id' => $client->id,
            'invoice_id' => $invoice->id,
            'payment_request_id' => $invoice->payment_request_id,
            'amount' => $invoice->total,
            'currency' => 'INR',
            'gateway' => 'manual',
            'status' => 'successful',
            'method' => 'upi',
            'paid_at' => $paidOn,
        ]);

        $transaction->forceFill(['created_at' => $paidOn, 'updated_at' => $paidOn])->save();

        $invoice->forceFill([
            'amount_paid' => $invoice->total,
            'status' => 'paid',
        ])->save();

        $invoice->paymentRequest?->forceFill(['status' => 'paid', 'paid_at' => $paidOn])->save();
    }

    /**
     * One certificate, so the issuance report is not empty.
     *
     * Forced past the pass mark on purpose: the seeded student has barely any
     * marks, and the point here is to exercise the report rather than to
     * pretend somebody earned it.
     */
    protected function seedCertificate(?User $admin): void
    {
        $enrolment = Enrollment::query()->with(['course', 'batch'])->first();

        if (! $enrolment || ! $enrolment->course) {
            return;
        }

        app(Credentials::class)->issueCertificate($enrolment, $admin, force: true);
    }
}
