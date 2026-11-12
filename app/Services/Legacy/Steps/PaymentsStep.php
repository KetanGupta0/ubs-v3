<?php

namespace App\Services\Legacy\Steps;

use App\Models\Enrollment;
use App\Models\Refund;
use App\Models\Transaction;
use App\Services\Admin\Auditor;
use App\Services\Legacy\Context;
use App\Services\Legacy\Ledger;
use App\Support\Money;

/**
 * v2 payments → v3 transactions (and refunds).
 *
 * Amounts go from decimal rupees to paise through Money, never by
 * multiplying a float. A Razorpay payment id appears exactly once. A cash,
 * UPI or bank transfer v2 recorded by hand becomes an offline payment, as
 * v3's own are, with who recorded it in the audit log. No invoices are
 * created after the fact: v2 issued none, and inventing numbered GST
 * invoices for past years would disturb the sequence and filed returns.
 * Students see receipts for these payments.
 *
 * A successful payment for an imported enrolment marks its fee as paid, which
 * is what opens paid lessons.
 */
class PaymentsStep extends Step
{
    public const STATUSES = [
        'paid' => 'successful', 'success' => 'successful', 'successful' => 'successful', 'completed' => 'successful', 'captured' => 'successful',
        'pending' => 'pending', 'created' => 'pending',
        'failed' => 'failed',
        'refunded' => 'refunded',
    ];

    public function name(): string
    {
        return 'payments';
    }

    public function sourceTables(): array
    {
        return ['payments'];
    }

    public function run(Context $context): void
    {
        $report = $context->report;

        $context->source()->table('payments')->orderBy('id')->each(function (object $row) use ($context, $report) {
            $report->count('payments', 'read');
            $source = "payments#{$row->id}";
            $userId = $context->ledger->targetId('users', (int) $row->student_id);

            if (! $userId) {
                $report->count('payments', 'skipped');
                $report->warn('payments', 'student_missing', $source, 'Its student was not imported; not imported. Its amount is missing from the reconciliation until it is.');

                return;
            }

            $checksum = Ledger::checksum($row);
            $entry = $context->ledger->find('payments', (int) $row->id);

            if ($entry && $entry->source_checksum === $checksum) {
                $report->count('payments', 'unchanged');

                return;
            }

            $transaction = $entry ? Transaction::query()->find($entry->target_id) : null;

            if (! $transaction && filled($row->razorpay_payment_id)
                && Transaction::query()->where('gateway_payment_id', $row->razorpay_payment_id)->exists()) {
                $report->count('payments', 'skipped');
                $report->warn('payments', 'duplicate_gateway_id', $source, 'v3 already holds a payment with this Razorpay payment id; not imported twice.');

                return;
            }

            $status = self::STATUSES[strtolower((string) $row->status)] ?? null;

            if (! $status) {
                $report->count('payments', 'skipped');
                $report->warn('payments', 'unknown_status', $source, 'Status on the old site was not one v3 knows; not imported.');

                return;
            }

            $offline = strtolower((string) $row->method) !== 'razorpay';
            $isNew = $transaction === null;
            $transaction ??= new Transaction;

            $transaction->forceFill([
                'user_id' => $userId,
                'gateway' => $offline ? 'offline' : 'razorpay',
                'method' => $offline ? strtolower((string) $row->method) : null,
                'gateway_order_id' => $row->razorpay_order_id,
                'gateway_payment_id' => $row->razorpay_payment_id,
                'gateway_signature' => $row->razorpay_signature,
                'amount' => Money::toPaise($row->amount),
                'currency' => strtoupper((string) ($row->currency ?: 'INR')),
                'status' => $status,
                'paid_at' => in_array($status, ['successful', 'refunded'], true) ? ($row->updated_at ?? $row->created_at) : null,
                'created_at' => $row->created_at ?? now(),
            ])->save();

            if ($status === 'refunded' && ! Refund::query()->where('transaction_id', $transaction->id)->exists()) {
                Refund::query()->create([
                    'transaction_id' => $transaction->id,
                    'amount' => $transaction->amount,
                    'reason' => 'Refunded on the old site.',
                    'status' => 'processed',
                    'processed_at' => $row->updated_at ?? now(),
                ]);
            }

            if ($isNew && ($row->recorded_by || filled($row->notes))) {
                app(Auditor::class)->action('payment.imported_notes', $transaction, array_filter([
                    'recorded_by' => $row->recorded_by ? ['from' => null, 'to' => 'user #'.($context->ledger->targetId('users', (int) $row->recorded_by) ?? 'not imported')] : null,
                    'notes' => filled($row->notes) ? ['from' => null, 'to' => $row->notes] : null,
                ]), "Recorded on the old site ({$source})");
            }

            // A paid fee opens the course, as Invoicer::recordPayment() does for v3's own payments.
            if ($status === 'successful' && $row->enrollment_id
                && ($enrolmentId = $context->ledger->targetId('enrollments', (int) $row->enrollment_id))) {
                Enrollment::query()->whereKey($enrolmentId)->update(['has_paid' => true]);
            }

            $context->ledger->record('payments', (int) $row->id, $transaction, $checksum);
            $report->count('payments', $isNew ? 'created' : 'updated');
        });
    }
}
