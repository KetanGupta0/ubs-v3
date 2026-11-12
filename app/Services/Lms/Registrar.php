<?php

namespace App\Services\Lms;

use App\Enums\Role;
use App\Jobs\ProcessRegistration;
use App\Models\Batch;
use App\Models\Enrollment;
use App\Models\Registration;
use App\Models\RegistrationForm;
use App\Models\User;
use App\Notifications\RegistrationApproved;
use App\Notifications\RegistrationRejected;
use App\Notifications\RegistrationToConfirm;
use App\Services\Admin\AccountCreator;
use App\Services\Admin\Auditor;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Registrations made through a public form, from the moment they arrive to the
 * moment somebody is on the course.
 *
 * The person filling the form is told the same thing whatever happens next, so
 * the form cannot be used to find out whether an address has an account. What
 * actually happens depends on the address:
 *
 * - **New:** an account is made through `AccountCreator`, which sends the way
 *   in. Only the owner of the address can use it, so the registration counts
 *   as confirmed.
 * - **An existing student:** the registration waits for that student to
 *   confirm it from their own panel. Anybody can type anybody's address, and
 *   enrolling on their word would let strangers put people on courses
 *   (decision X5).
 * - **A staff or client account:** nothing is sent, and the team sees why.
 *
 * Matching happens in a queued job rather than in the request, so making an
 * account (and hashing its password) does not make one answer slower than
 * the other.
 */
class Registrar
{
    public function __construct(
        protected AccountCreator $accounts,
        protected Enroller $enroller,
        protected Auditor $auditor,
    ) {}

    /**
     * Store what was sent, and queue the rest.
     *
     * A second submission from the same address to the same form is dropped
     * without a word: it is not a second place, and saying so would tell the
     * sender that the address had been used.
     *
     * @param  array{name: string, email: string, mobile: ?string, details: array<string, string>, answers: array<string, string>}  $data
     */
    public function receive(RegistrationForm $form, array $data): void
    {
        DB::transaction(function () use ($form, $data) {
            $email = mb_strtolower(trim($data['email']));

            $exists = Registration::query()
                ->where('registration_form_id', $form->id)
                ->where('email', $email)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                return;
            }

            $registration = Registration::query()->create([
                'registration_form_id' => $form->id,
                'batch_id' => $form->batch_id,
                'name' => trim($data['name']),
                'email' => $email,
                'mobile' => $data['mobile'] ?: null,
                'details' => $data['details'] ?: null,
                'answers' => $data['answers'] ?: null,
                'status' => 'received',
            ]);

            ProcessRegistration::dispatch($registration->id)->afterCommit();
        });
    }

    /** Match a received registration to an account. Safe to run twice. */
    public function process(Registration $registration): void
    {
        if ($registration->status !== 'received') {
            return;
        }

        $form = $registration->form()->with('course', 'batch')->firstOrFail();
        $user = User::query()->where('email', $registration->email)->first();

        if (! $user) {
            $this->createAccount($registration, $form);
            $this->confirmed($registration, notify: false);

            return;
        }

        $registration->user_id = $user->id;

        if (! $user->isStudent()) {
            $registration->forceFill([
                'status' => 'awaiting_review',
                'note' => 'This address belongs to a '.$user->role->value.' account, not a student one. Nothing was sent to it.',
            ])->save();

            return;
        }

        $current = Enrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $form->course_id)
            ->where('status', '!=', 'dropped')
            ->first();

        if ($current) {
            $registration->forceFill([
                'status' => 'approved',
                'enrollment_id' => $current->id,
                'decided_at' => now(),
                'note' => 'Already on this course, so nothing was sent.',
            ])->save();

            return;
        }

        $registration->forceFill(['status' => 'awaiting_confirmation'])->save();
        $user->notify(new RegistrationToConfirm($registration));
    }

    /** The account holder says it was them. */
    public function confirm(Registration $registration): void
    {
        if ($registration->status !== 'awaiting_confirmation') {
            throw new InvalidArgumentException('This registration is not waiting for you to confirm it.');
        }

        $registration->forceFill(['confirmed_at' => now()])->save();

        // They are looking at the result on screen, so no message follows.
        $this->confirmed($registration, notify: false);
    }

    /** The account holder says it was not them, or no longer wants it. */
    public function decline(Registration $registration): void
    {
        if (! in_array($registration->status, ['awaiting_confirmation', 'awaiting_review'], true)) {
            throw new InvalidArgumentException('This registration can no longer be withdrawn.');
        }

        $registration->forceFill([
            'status' => 'declined',
            'decided_at' => now(),
        ])->save();
    }

    /**
     * Put the person on the course.
     *
     * Refused for an address whose holder has not confirmed it, for an account
     * that is not a student's, and for a batch with no seats left.
     */
    public function approve(Registration $registration, ?User $actor, ?Batch $batch = null, bool $notify = true): Enrollment
    {
        // A rejected registration can be approved after all: people change
        // their minds. One the holder never confirmed cannot, however it
        // got to where it is.
        $problem = match (true) {
            $registration->status === 'approved' => 'This registration is already approved.',
            $registration->status === 'declined' => 'The account holder declined this registration.',
            $registration->status === 'received' => 'This registration has not been matched to an account yet. Try again in a minute.',
            ! $registration->account_created && ! $registration->confirmed_at && (bool) $registration->user?->isStudent() => 'The account holder has not confirmed this yet. Until they do, it could be somebody else typing their address.',
            default => null,
        };

        if ($problem) {
            throw new InvalidArgumentException($problem);
        }

        $student = $registration->user;

        if (! $student?->isStudent()) {
            throw new InvalidArgumentException('Only a student account can be put on a course, and this address belongs to another kind of account.');
        }

        $form = $registration->form()->with('course')->firstOrFail();
        $batch ??= $registration->batch;

        if ($batch && $batch->course_id !== $form->course_id) {
            throw new InvalidArgumentException('That batch belongs to another course.');
        }

        if ($batch && $batch->seatsLeft() === 0) {
            throw new InvalidArgumentException("{$batch->name} is full. Move this registration to another batch first.");
        }

        $enrolment = $this->enroller->enrol(
            $student,
            $form->course,
            $batch,
            source: 'form',
            waiveFee: $form->fee === 'free',
            notify: false,
        );

        $registration->forceFill([
            'status' => 'approved',
            'enrollment_id' => $enrolment->id,
            'batch_id' => $batch?->id,
            'decided_by' => $actor?->id,
            'decided_at' => now(),
        ])->save();

        $this->auditor->actingAs($actor)->action('registration.approved', $registration, [
            'course' => $form->course->title,
            'batch' => $batch?->name,
        ], "{$registration->name} on {$form->course->title}");

        if ($notify) {
            $student->notify(new RegistrationApproved($registration, $enrolment));
        }

        return $enrolment;
    }

    public function reject(Registration $registration, User $actor, ?string $reason = null): void
    {
        if (! in_array($registration->status, ['awaiting_review', 'awaiting_confirmation', 'received'], true)) {
            throw new InvalidArgumentException('Only a registration that is still waiting can be rejected.');
        }

        // Only somebody who knows about the registration hears that it was
        // turned down: a new account, or a student who confirmed it.
        $tell = $registration->user?->isStudent()
            && ($registration->account_created || $registration->confirmed_at);

        $registration->forceFill([
            'status' => 'rejected',
            'note' => filled($reason) ? trim($reason) : null,
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ])->save();

        $this->auditor->actingAs($actor)->action('registration.rejected', $registration, [
            'reason' => $registration->note,
        ], "{$registration->name} on {$registration->form->course->title}");

        if ($tell) {
            $registration->user->notify(new RegistrationRejected($registration));
        }
    }

    /** Change the batch a registration is for, moving the enrolment too once there is one. */
    public function moveTo(Registration $registration, Batch $batch, User $actor): void
    {
        $form = $registration->form;

        if ($batch->course_id !== $form->course_id) {
            throw new InvalidArgumentException('That batch belongs to another course.');
        }

        $from = $registration->batch?->name;

        if ($registration->status === 'approved' && $registration->enrollment) {
            $this->enroller->moveTo($registration->enrollment, $batch);
        }

        $registration->forceFill(['batch_id' => $batch->id])->save();

        $this->auditor->actingAs($actor)->action('registration.moved', $registration, [
            'batch' => ['from' => $from, 'to' => $batch->name],
        ], $registration->name);
    }

    /* -------------------------------------------------------------------- */

    protected function createAccount(Registration $registration, RegistrationForm $form): void
    {
        // A mobile number already on another account stays on the
        // registration only: numbers are unique across accounts.
        $mobile = $registration->mobile
            && ! User::query()->where('mobile', $registration->mobile)->exists()
            ? $registration->mobile
            : null;

        $details = $registration->details ?? [];

        $created = $this->accounts->create([
            'name' => $registration->name,
            'email' => $registration->email,
            'mobile' => $mobile,
            'course_of_study' => $details['course_of_study'] ?? null,
            'graduation_year' => is_numeric($details['graduation_year'] ?? null) ? (int) $details['graduation_year'] : null,
            'city' => $details['city'] ?? null,
        ], Role::Student, $this->welcomeNote($form, $registration));

        $registration->forceFill([
            'user_id' => $created['user']->id,
            'account_created' => true,
            'confirmed_at' => now(),
        ])->save();
    }

    /** What happens once the address is known to be theirs. */
    protected function confirmed(Registration $registration, bool $notify): void
    {
        $registration->forceFill(['status' => 'awaiting_review'])->save();

        if ($registration->form->approval === 'auto') {
            try {
                $this->approve($registration, null, notify: $notify);
            } catch (InvalidArgumentException $e) {
                // A full batch, most likely. It waits for a person instead.
                $registration->forceFill(['note' => $e->getMessage()])->save();
            }
        }
    }

    /** The line added to the welcome message for an account made by this form. */
    protected function welcomeNote(RegistrationForm $form, Registration $registration): string
    {
        $course = $form->course->title;
        $full = $registration->batch && $registration->batch->seatsLeft() === 0;

        if ($form->approval === 'review' || $full) {
            return "This account was made when you registered for {$course}. The team will look at your registration and write to you once it is decided; your dashboard shows where it stands once you sign in.";
        }

        $fee = $form->feeInPaise();

        return $fee === 0
            ? "This account was made when you registered for {$course}, and you are on the course. Sign in to start."
            : "This account was made when you registered for {$course}, and you are on the course. The fee of ".Money::display($fee).' is waiting under Payments; the lessons that need it open once it is paid.';
    }
}
