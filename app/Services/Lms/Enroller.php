<?php

namespace App\Services\Lms;

use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Notifications\PaymentRequested;
use App\Services\Billing\Invoicer;
use App\Services\Chat\Rooms;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Putting a student on a course.
 *
 * A free course enrols straight away. A paid one enrols too, but unpaid, so the
 * student can see the syllabus and the schedule while the fee is settled. Only
 * the lessons marked as requiring payment stay sealed. Making somebody pay
 * before they can see what they are paying for is how a sale is lost.
 */
class Enroller
{
    public function __construct(
        protected Invoicer $invoicer,
        protected Activity $activity,
        protected Rooms $rooms,
    ) {}

    /**
     * @param  string  $source  How they came to be here: self, admin, college.
     */
    public function enrol(
        User $student,
        Course $course,
        ?Batch $batch = null,
        string $source = 'admin',
        bool $waiveFee = false,
        bool $notify = true,
    ): Enrollment {
        $existing = Enrollment::query()
            ->where('user_id', $student->id)
            ->where('course_id', $course->id)
            ->where('batch_id', $batch?->id)
            ->first();

        if ($existing) {
            // Re-enrolling somebody who dropped puts them back rather than
            // failing on a unique index, which is what the caller meant.
            if ($existing->status === 'dropped') {
                $existing->forceFill(['status' => 'active', 'dropped_at' => null])->save();
            }

            return $existing;
        }

        $free = $course->isFree() || $waiveFee;

        return DB::transaction(function () use ($student, $course, $batch, $source, $free, $notify) {
            $enrolment = Enrollment::query()->create([
                'user_id' => $student->id,
                'course_id' => $course->id,
                'batch_id' => $batch?->id,
                'status' => 'active',
                'source' => $source,
                'enrolled_at' => now(),
                'has_paid' => $free,
            ]);

            if (! $free) {
                $enrolment->forceFill([
                    'payment_request_id' => $this->raiseFee($student, $course, $batch, $notify)->id,
                ])->save();
            }

            $batch?->recountSeats();

            $this->activity->record($student, 'enrolled', $course, [
                'title' => $course->title,
                'batch' => $batch?->name,
            ], $course->id, $batch?->id);

            return $enrolment;
        });
    }

    /** Raise the fee, and issue its invoice at the same moment. */
    public function raiseFee(User $student, Course $course, ?Batch $batch, bool $notify = true): PaymentRequest
    {
        $request = PaymentRequest::query()->create([
            'user_id' => $student->id,
            'payable_type' => Course::class,
            'payable_id' => $course->id,
            'title' => $course->title.($batch ? " — {$batch->name}" : ''),
            'description' => 'Course fee'.($batch?->starts_on ? ', for the batch starting '.$batch->starts_on->format('j M Y').'.' : '.'),
            'due_on' => $batch?->starts_on ?? today()->addDays(7),
        ]);

        // A course fee is priced inclusive of tax on the public page, so the
        // subtotal is worked back out rather than the tax being added on top
        // of a number the student has already read as the total.
        $rate = $this->invoicer->defaultTaxRate();
        $subtotal = Money::taxableWithin($course->effectivePrice(), $rate);

        $request->price($subtotal, $rate)->save();

        $invoice = $this->invoicer->issueFor($request);

        if ($notify) {
            $student->notify(new PaymentRequested($request, $invoice->number));
        }

        return $request;
    }

    /**
     * Mark the fee settled.
     *
     * Called when a payment lands against a course, so the lessons that were
     * waiting on it open without anybody having to do anything.
     */
    public function markPaid(Enrollment $enrolment): void
    {
        $enrolment->forceFill(['has_paid' => true])->save();
    }

    public function drop(Enrollment $enrolment, ?string $reason = null): void
    {
        $enrolment->forceFill([
            'status' => 'dropped',
            'dropped_at' => now(),
        ])->save();

        $enrolment->batch?->recountSeats();
        $this->syncGroup($enrolment->batch);
    }

    /**
     * Change where an enrolment stands: active, on hold, completed or dropped.
     *
     * Dropping goes through drop() so the seat is given back. Coming back from
     * dropped clears the date it was dropped, so the record does not claim both.
     */
    public function setStatus(Enrollment $enrolment, string $status): void
    {
        if (! in_array($status, Enrollment::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown enrolment status [{$status}].");
        }

        if ($status === 'dropped') {
            $this->drop($enrolment);

            return;
        }

        $enrolment->forceFill([
            'status' => $status,
            'dropped_at' => null,
            'completed_at' => $status === 'completed' ? ($enrolment->completed_at ?? now()) : null,
        ])->save();

        $enrolment->batch?->recountSeats();
        $this->syncGroup($enrolment->batch);
    }

    /**
     * Move a student to another batch of the same course.
     *
     * Their progress, payments and certificates belong to the enrolment, so
     * they come along. Attendance and warnings belong to the sessions they
     * happened in, so they stay with the old batch, which is where they
     * happened. Both batches recount their seats, and both groups are put in
     * step, so the student leaves one chat and joins the other.
     */
    public function moveTo(Enrollment $enrolment, Batch $to): Enrollment
    {
        if ($to->course_id !== $enrolment->course_id) {
            throw new InvalidArgumentException('A student can only move to another batch of the same course.');
        }

        $from = $enrolment->batch;

        if ($from?->id === $to->id) {
            return $enrolment;
        }

        DB::transaction(function () use ($enrolment, $to) {
            $enrolment->forceFill(['batch_id' => $to->id])->save();
        });

        $from?->recountSeats();
        $to->recountSeats();

        $this->syncGroup($from);
        $this->syncGroup($to);

        $this->activity->record($enrolment->user, 'moved_batch', $enrolment->course, [
            'from' => $from?->name,
            'to' => $to->name,
        ], $enrolment->course_id, $to->id);

        return $enrolment->refresh();
    }

    /**
     * Let a student off their fee.
     *
     * The unpaid request and its invoice are cancelled, so nobody is chased for
     * money we decided not to take, and the course opens. A fee that has been
     * partly paid cannot be waived this way: the money taken has to be settled
     * or refunded first, or the books and the course would disagree.
     */
    public function waiveFee(Enrollment $enrolment): void
    {
        $request = $enrolment->paymentRequest;

        if ($request && $request->transactions()->successful()->exists()) {
            throw new InvalidArgumentException('Part of this fee has been paid, so it cannot be waived. Record the rest, or refund what was paid.');
        }

        DB::transaction(function () use ($enrolment, $request) {
            if ($request && $request->status === 'pending') {
                $request->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
                $request->invoice?->forceFill(['status' => 'cancelled'])->save();
            }

            $enrolment->forceFill(['has_paid' => true])->save();
        });
    }

    /** Keep the batch's group chat in step with who is on the batch. */
    protected function syncGroup(?Batch $batch): void
    {
        if ($batch) {
            $this->rooms->forBatch($batch);
        }
    }
}
