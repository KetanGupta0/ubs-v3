<?php

namespace App\Services\Lms;

use App\Models\Announcement;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\PaymentRequest;
use App\Models\StudentWarning;
use App\Models\User;
use App\Support\Money;

/**
 * Everything about one student's learning, for the people who look after them.
 *
 * The admin profile shows it in one place: what they are on, how they are
 * doing, what they owe, what they have been given and what they have been
 * warned about. Results come from ResultCard, the same arithmetic the student
 * sees on their own result page, so the two can never disagree.
 */
class StudentRecord
{
    public function __construct(protected ResultCard $results) {}

    /**
     * @param  bool  $withPayments  Off for staff who may not see billing: the
     *                              figures are left out, not just hidden.
     * @return array<string, mixed>
     */
    public function for(User $student, bool $withPayments = true): array
    {
        $enrolments = Enrollment::query()
            ->where('user_id', $student->id)
            ->with(['course', 'batch:id,name,course_id', 'paymentRequest:id,status,total', 'paymentRequest.invoice:id,payment_request_id,total,amount_paid'])
            ->latest('enrolled_at')
            ->get();

        return [
            'enrolments' => $enrolments->map(fn (Enrollment $enrolment) => $this->enrolment($enrolment))->values(),
            'payments' => $withPayments ? $this->payments($student) : [],
            'certificates' => $this->certificates($student),
            'warnings' => $this->warnings($student),
            'notices' => $this->notices($student),
            'counts' => [
                'enrolments' => $enrolments->count(),
                'active' => $enrolments->where('status', 'active')->count(),
                'unpaid' => $enrolments->whereIn('status', ['active', 'on_hold'])->where('has_paid', false)->count(),
                'openWarnings' => StudentWarning::query()->where('user_id', $student->id)->whereNull('resolved_at')->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function enrolment(Enrollment $enrolment): array
    {
        $card = $this->results->for($enrolment);
        $balance = $enrolment->paymentRequest?->invoice?->balance() ?? 0;

        return [
            'id' => $enrolment->id,
            'course' => $enrolment->course?->title,
            'courseId' => $enrolment->course_id,
            'batch' => $enrolment->batch?->name,
            'batchId' => $enrolment->batch_id,
            'status' => $enrolment->status,
            'statusLabel' => $enrolment->statusLabel(),
            'enrolledOn' => $enrolment->enrolled_at?->format('j M Y'),
            'fee' => match (true) {
                $enrolment->has_paid && ! $enrolment->payment_request_id => 'Free',
                $enrolment->has_paid && $enrolment->paymentRequest?->status === 'cancelled' => 'Waived',
                $enrolment->has_paid => 'Paid',
                $balance > 0 => Money::display($balance).' due',
                default => 'Not paid',
            },
            'hasPaid' => $enrolment->has_paid,
            'progress' => $enrolment->progress_percent,
            'result' => [
                'overall' => $card['overall'],
                'grade' => $card['grade'],
                'passing' => $card['passing'],
                'passMark' => $card['passMark'],
                'shortOnAttendance' => $card['shortOnAttendance'],
                'attendance' => $card['attendance']['percent'] ?? null,
                'attendanceFloor' => $card['attendanceFloor'],
                'quizzes' => ['taken' => $card['quizzes']['taken'], 'total' => $card['quizzes']['total'], 'percent' => $card['quizzes']['percent']],
                'assignments' => ['submitted' => $card['assignments']['submitted'], 'total' => $card['assignments']['total'], 'percent' => $card['assignments']['percent']],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function payments(User $student): array
    {
        return PaymentRequest::query()
            ->where('user_id', $student->id)
            ->with('invoice:id,payment_request_id,number,total,amount_paid,status')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (PaymentRequest $request) => [
                'id' => $request->id,
                'reference' => $request->reference,
                'title' => $request->title,
                'total' => Money::display($request->total),
                'paid' => Money::display($request->invoice?->amount_paid ?? 0),
                'status' => $request->status,
                'invoice' => $request->invoice?->number,
                'raisedOn' => $request->created_at?->format('j M Y'),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function certificates(User $student): array
    {
        return Certificate::query()
            ->where('user_id', $student->id)
            ->latest('issued_at')
            ->get()
            ->map(fn (Certificate $certificate) => [
                'id' => $certificate->id,
                'number' => $certificate->number,
                'title' => $certificate->title,
                'issuedOn' => $certificate->issued_at?->format('j M Y'),
                'grade' => $certificate->grade,
                'revoked' => $certificate->revoked_at !== null,
                'revokedReason' => $certificate->revoked_reason,
            ])
            ->all();
    }

    /** Notices sent to this student alone. @return array<int, array<string, mixed>> */
    protected function notices(User $student): array
    {
        return Announcement::query()
            ->where('audience', 'student')
            ->where('user_id', $student->id)
            ->with('author:id,name')
            ->latest('published_at')
            ->limit(50)
            ->get()
            ->map(fn (Announcement $notice) => [
                'id' => $notice->id,
                'title' => $notice->title,
                'body' => $notice->body,
                'pinned' => $notice->is_pinned,
                'by' => $notice->author?->name,
                'sentOn' => $notice->published_at?->format('j M Y, g:i a'),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function warnings(User $student): array
    {
        return StudentWarning::query()
            ->where('user_id', $student->id)
            ->with('batch:id,name')
            ->latest('id')
            ->get()
            ->map(fn (StudentWarning $warning) => [
                'id' => $warning->id,
                'level' => $warning->level,
                'reason' => $warning->reason,
                // Staff only. The student never sees this note.
                'privateNote' => $warning->private_note,
                'batch' => $warning->batch?->name,
                'issuedOn' => $warning->created_at?->format('j M Y'),
                'acknowledged' => $warning->acknowledged_at !== null,
                'resolved' => $warning->resolved_at !== null,
            ])
            ->all();
    }
}
