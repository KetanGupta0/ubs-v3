<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's answers to a registration form.
 *
 * received                 stored, not yet matched to an account (a queued job does that)
 * awaiting_confirmation    the address belongs to an existing student, who has to say it was them
 * awaiting_review          waiting for somebody on the team to approve it
 * approved                 on the course
 * rejected                 turned away by the team
 * declined                 the account holder said it was not them, or changed their mind
 */
class Registration extends Model
{
    public const STATUSES = [
        'received' => 'Received',
        'awaiting_confirmation' => 'Waiting for the student to confirm',
        'awaiting_review' => 'Waiting for review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'declined' => 'Declined by the student',
    ];

    /** Statuses that no longer hold a place on the form. */
    public const CLOSED = ['rejected', 'declined'];

    protected $fillable = [
        'registration_form_id', 'user_id', 'enrollment_id', 'batch_id', 'name', 'email',
        'mobile', 'details', 'answers', 'status', 'account_created', 'note',
        'confirmed_at', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'answers' => 'array',
            'account_created' => 'boolean',
            'confirmed_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(RegistrationForm::class, 'registration_form_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** A student's own registrations, the only way the student panel reads them. */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Whether the team can still approve, reject or move it. */
    public function isUndecided(): bool
    {
        return in_array($this->status, ['awaiting_review', 'awaiting_confirmation', 'received'], true);
    }
}
