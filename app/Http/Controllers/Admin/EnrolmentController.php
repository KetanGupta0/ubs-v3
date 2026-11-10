<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Admin\Auditor;
use App\Services\Lms\Enroller;
use App\Support\Money;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Every enrolment on the platform, in one list.
 *
 * A batch's own screen manages the people on that batch. This is the view
 * across all of them: who is on what, who has not paid, who is on hold, and
 * the one place to put somebody on a course, move them between batches, take
 * a payment made by bank transfer or let them off a fee.
 *
 * The rules for each of those live in Enroller, never here, so the batch
 * screen, the college desk and this list cannot drift apart.
 */
class EnrolmentController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = $this->table();

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        $user = $request->user();

        return Inertia::render('admin/enrolments/Index', [
            'table' => $table->toArray($request),
            'counts' => [
                'active' => Enrollment::query()->where('status', 'active')->count(),
                'unpaid' => Enrollment::query()->whereIn('status', ['active', 'on_hold'])->where('has_paid', false)->count(),
                'onHold' => Enrollment::query()->where('status', 'on_hold')->count(),
            ],
            'courses' => $this->courseOptions(),
            'batches' => $this->batchOptions(),
            'statuses' => collect(Enrollment::STATUSES)
                ->map(fn (string $status) => ['value' => $status, 'label' => str($status)->replace('_', ' ')->ucfirst()->toString()])
                ->values(),
            'canManage' => $user->hasPermission('students.update'),
            'canTakePayments' => $user->hasPermission('billing.manage'),
            // Only fetched when somebody opens "Enrol a student".
            'studentOptions' => Inertia::optional(fn () => User::query()
                ->role(Role::Student)
                ->orderBy('name')
                ->limit(5000)
                ->get(['id', 'name', 'email'])
                ->map(fn (User $student) => [
                    'value' => $student->id,
                    'label' => $student->name,
                    'description' => $student->email,
                ])),
        ]);
    }

    /** Put somebody on a course by hand. */
    public function store(Request $request, Enroller $enroller, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'student_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', Role::Student->value)],
            'course_id' => ['required', 'integer', Rule::exists('courses', 'id')],
            'batch_id' => ['nullable', 'integer', Rule::exists('batches', 'id')],
            'waive_fee' => ['boolean'],
            'over_capacity' => ['boolean'],
        ], [
            'student_id.required' => 'Choose a student.',
            'course_id.required' => 'Choose a course.',
        ]);

        $student = User::query()->findOrFail($validated['student_id']);
        $course = Course::query()->findOrFail($validated['course_id']);
        $batch = $validated['batch_id'] ? Batch::query()->findOrFail($validated['batch_id']) : null;

        if ($batch && $batch->course_id !== $course->id) {
            return back()->withErrors(['batch_id' => 'That batch belongs to a different course.']);
        }

        if ($batch && $this->isFull($batch) && ! $validated['over_capacity']) {
            return back()->withErrors(['batch_id' => 'That batch is full. Tick "Add over capacity" to add them anyway.']);
        }

        $already = Enrollment::query()
            ->where('user_id', $student->id)
            ->where('course_id', $course->id)
            ->where('batch_id', $batch?->id)
            ->whereIn('status', ['active', 'on_hold', 'completed'])
            ->exists();

        if ($already) {
            return back()->withErrors(['student_id' => "{$student->name} is already on that ".($batch ? 'batch' : 'course').'.']);
        }

        $enrolment = $enroller->enrol(
            $student,
            $course,
            $batch,
            source: 'admin',
            waiveFee: $validated['waive_fee'],
            notify: $this->boolInput($request, 'notify', true),
        );

        $auditor->action('enrolment.created', $enrolment, [
            'student' => $student->name,
            'course' => $course->title,
            'batch' => $batch?->name,
            'fee_waived' => $validated['waive_fee'],
            'over_capacity' => $batch !== null && $validated['over_capacity'],
        ], "{$student->name} on {$course->title}");

        return back()->with('success', "{$student->name} is enrolled on {$course->title}."
            .($enrolment->has_paid ? '' : ' The fee has been raised and the invoice sent.'));
    }

    public function updateStatus(Request $request, Enrollment $enrolment, Enroller $enroller, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'status' => ['required', Rule::in(Enrollment::STATUSES)],
        ]);

        $before = $enrolment->status;

        if ($before === $validated['status']) {
            return back();
        }

        $enroller->setStatus($enrolment, $validated['status']);

        $auditor->action('enrolment.status_changed', $enrolment, [
            'status' => ['from' => $before, 'to' => $validated['status']],
        ], $this->describe($enrolment));

        return back()->with('success', 'Enrolment is now '.str($validated['status'])->replace('_', ' ').'.');
    }

    public function move(Request $request, Enrollment $enrolment, Enroller $enroller, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'batch_id' => ['required', 'integer', Rule::exists('batches', 'id')],
            'over_capacity' => ['boolean'],
        ], ['batch_id.required' => 'Choose the batch to move them to.']);

        $to = Batch::query()->findOrFail($validated['batch_id']);

        if ($to->course_id !== $enrolment->course_id) {
            return back()->withErrors(['batch_id' => 'They can only move to another batch of the same course.']);
        }

        if ($to->id === $enrolment->batch_id) {
            return back()->withErrors(['batch_id' => 'They are already on that batch.']);
        }

        // One row per student, course and batch: a place they held on that
        // batch before, and dropped, is brought back rather than duplicated.
        $earlier = Enrollment::query()
            ->where('user_id', $enrolment->user_id)
            ->where('course_id', $enrolment->course_id)
            ->where('batch_id', $to->id)
            ->first();

        if ($earlier) {
            return back()->withErrors(['batch_id' => 'They already have a place on that batch from before. Change that enrolment back to active instead.']);
        }

        if ($this->isFull($to) && ! $validated['over_capacity']) {
            return back()->withErrors(['batch_id' => 'That batch is full. Tick "Add over capacity" to move them anyway.']);
        }

        $from = $enrolment->batch?->name;
        $enroller->moveTo($enrolment, $to);

        $auditor->action('enrolment.moved', $enrolment, [
            'batch' => ['from' => $from, 'to' => $to->name],
        ], $this->describe($enrolment));

        return back()->with('success', "Moved to {$to->name}.");
    }

    public function waiveFee(Request $request, Enrollment $enrolment, Enroller $enroller, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], ['reason.required' => 'Say why the fee is being waived. It goes in the audit log.']);

        if ($enrolment->has_paid) {
            return back()->with('error', 'This enrolment has nothing left to pay.');
        }

        try {
            $enroller->waiveFee($enrolment);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $auditor->action('enrolment.fee_waived', $enrolment, [
            'reason' => $validated['reason'],
            'payment_request' => $enrolment->paymentRequest?->reference,
        ], $this->describe($enrolment));

        return back()->with('success', 'Fee waived. The course is fully open and the invoice is cancelled.');
    }

    protected function table(): Table
    {
        return Table::for(Enrollment::query()->with([
            'user:id,name,email',
            'course:id,title,type',
            'batch:id,name,course_id',
            'paymentRequest:id,reference,status,total',
            'paymentRequest.invoice:id,payment_request_id,number,total,amount_paid,status',
        ]))
            ->searchable(['user.name', 'user.email', 'course.title', 'batch.name'])
            ->defaultSort('-enrolled_at')
            ->exportName('enrolments')
            ->columns([
                Column::make('student', 'Student'),
                Column::make('course', 'Course'),
                Column::make('batch', 'Batch'),
                Column::make('status', 'Status')->sortable(),
                Column::make('fee', 'Fee'),
                Column::make('progress_percent', 'Progress')->numeric()->sortable(),
                Column::make('source', 'Source')->hidden(),
                Column::make('enrolled_at', 'Enrolled')->sortable(),
            ])
            ->filters([
                Filter::select('course', $this->courseOptions()->pluck('label', 'value')->all(), 'Course')
                    ->column('course_id')
                    ->placeholder('Any course'),
                Filter::select('batch', $this->batchOptions()->pluck('label', 'value')->all(), 'Batch')
                    ->column('batch_id')
                    ->placeholder('Any batch'),
                Filter::multi('status', Enrollment::STATUSES, 'Status'),
                Filter::select('fee', ['unpaid' => 'Not paid yet', 'paid' => 'Paid or free'], 'Fee')
                    ->placeholder('Any')
                    ->using(fn (Builder $query, string $value) => match ($value) {
                        'unpaid' => $query->where('has_paid', false),
                        'paid' => $query->where('has_paid', true),
                        default => $query,
                    }),
                Filter::select('source', ['self' => 'Signed up', 'admin' => 'Added by us', 'college' => 'College group'], 'Source')
                    ->placeholder('Any'),
                Filter::dateRange('enrolled_at', 'Enrolled between'),
            ])
            ->transform(fn (Enrollment $enrolment) => $this->row($enrolment));
    }

    /** @return array<string, mixed> */
    protected function row(Enrollment $enrolment): array
    {
        $invoice = $enrolment->paymentRequest?->invoice;
        $balance = $invoice?->balance() ?? 0;

        return [
            'id' => $enrolment->id,
            'student' => $enrolment->user?->name,
            'studentId' => $enrolment->user_id,
            'email' => $enrolment->user?->email,
            'course' => $enrolment->course?->title,
            'courseId' => $enrolment->course_id,
            'batch' => $enrolment->batch?->name,
            'batchId' => $enrolment->batch_id,
            'status' => $enrolment->status,
            'statusLabel' => $enrolment->statusLabel(),
            'fee' => match (true) {
                $enrolment->has_paid && ! $enrolment->payment_request_id => 'Free',
                $enrolment->has_paid && $enrolment->paymentRequest?->status === 'cancelled' => 'Waived',
                $enrolment->has_paid => 'Paid',
                $balance > 0 => Money::display($balance).' due',
                default => 'Not paid',
            },
            'hasPaid' => $enrolment->has_paid,
            'paymentRequestId' => $enrolment->payment_request_id,
            'balance' => $balance,
            'balanceLabel' => $balance > 0 ? Money::display($balance) : null,
            'invoiceNumber' => $invoice?->number,
            'progress_percent' => $enrolment->progress_percent,
            'source' => $enrolment->source,
            'enrolled_at' => $enrolment->enrolled_at?->format('j M Y'),
        ];
    }

    protected function courseOptions()
    {
        return Course::query()
            ->orderBy('title')
            ->get(['id', 'title', 'type'])
            ->map(fn (Course $course) => ['value' => $course->id, 'label' => $course->title]);
    }

    protected function batchOptions()
    {
        return Batch::query()
            ->with('course:id,title')
            ->orderByDesc('starts_on')
            ->get(['id', 'name', 'course_id', 'starts_on', 'capacity', 'seats_taken'])
            ->map(fn (Batch $batch) => [
                'value' => $batch->id,
                'label' => $batch->name,
                'courseId' => $batch->course_id,
                'description' => trim(($batch->course?->title ?? '').($batch->capacity ? " · {$batch->seats_taken}/{$batch->capacity} seats" : '')),
                // From the stored count, which recountSeats() keeps honest; the
                // action itself counts again before it lets anybody in.
                'full' => $batch->capacity !== null && $batch->seats_taken >= $batch->capacity,
            ]);
    }

    protected function isFull(Batch $batch): bool
    {
        return $batch->capacity !== null && $batch->activeStudentCount() >= $batch->capacity;
    }

    protected function describe(Enrollment $enrolment): string
    {
        $enrolment->loadMissing(['user:id,name', 'course:id,title']);

        return ($enrolment->user?->name ?? 'Student').' on '.($enrolment->course?->title ?? 'a course');
    }
}
