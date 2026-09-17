<?php

namespace App\Services\Lms;

use App\Models\Batch;
use App\Models\LiveSession;
use App\Models\StudentWarning;
use App\Models\User;
use App\Notifications\WarningIssued;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * Telling a student, privately, that they are not paying attention.
 *
 * Three rungs, climbed in order: a quiet word, then a formal warning, then a
 * guardian is told. The ladder matters. A first offence that goes straight to
 * somebody's parent is how a student stops telling you anything true, and the
 * point of the whole mechanism is to get them back in the room, not to build a
 * case against them.
 *
 * Nothing here is ever visible to another student.
 */
class Warnings
{
    public function __construct(protected SmsSender $sms) {}

    public function issue(
        User $student,
        string $reason,
        ?Batch $batch = null,
        ?LiveSession $session = null,
        ?User $issuedBy = null,
        ?string $level = null,
        ?string $privateNote = null,
        ?string $guardianContact = null,
    ): StudentWarning {
        $level ??= StudentWarning::nextLevelFor($student, $batch?->id);

        $warning = StudentWarning::query()->create([
            'user_id' => $student->id,
            'batch_id' => $batch?->id,
            'live_session_id' => $session?->id,
            'level' => $level,
            'reason' => $reason,
            'private_note' => $privateNote,
            'issued_by' => $issuedBy?->id,
            'guardian_contact' => $guardianContact,
        ]);

        // The student is told every time, whatever the rung. A warning they
        // never see cannot change anything, and turning up to a meeting about
        // three warnings you had not heard of is its own injury.
        $student->notify(new WarningIssued($warning));

        if ($level === 'escalation') {
            $this->notifyGuardian($warning);
        }

        return $warning;
    }

    public function acknowledge(StudentWarning $warning): StudentWarning
    {
        if ($warning->acknowledged_at === null) {
            $warning->forceFill(['acknowledged_at' => now()])->save();
        }

        return $warning;
    }

    /** Close it out, once things have improved. */
    public function resolve(StudentWarning $warning, ?string $note = null): StudentWarning
    {
        $warning->forceFill([
            'resolved_at' => now(),
            'private_note' => $note ?? $warning->private_note,
        ])->save();

        return $warning;
    }

    /**
     * Tell whoever the student gave as their guardian.
     *
     * Only at the top rung, and only to a number the student themselves
     * provided. Chasing down a parent's number some other way is not something
     * a training provider should be doing.
     */
    protected function notifyGuardian(StudentWarning $warning): void
    {
        $contact = $warning->guardian_contact
            ?: $warning->user->profile?->guardian_mobile
            ?: null;

        if (blank($contact)) {
            Log::info('Warning escalated with no guardian contact on file', [
                'warning_id' => $warning->id,
            ]);

            return;
        }

        $message = sprintf(
            '%s: we have raised a concern about %s\'s participation in class. Please speak to them, or call us to discuss.',
            config('company.name'),
            $warning->user->name,
        );

        try {
            $this->sms->send($contact, $message);
            $warning->forceFill(['guardian_notified_at' => now()])->save();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
