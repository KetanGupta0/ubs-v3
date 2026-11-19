<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's decision on a flagged attempt (§9.1, layer 4). The student reads
 * the outcome and the reason, never the note, and may reply once before it is
 * final. A first decision to clear it is not sent: they were never told
 * anything was looked at.
 */
class QuizIntegrityDecision extends Model
{
    public const OUTCOMES = [
        'cleared' => 'Cleared',
        'discuss' => 'To discuss with the student',
        'voided' => 'Attempt voided',
        'warned' => 'Warning issued',
    ];

    protected $guarded = ['id'];

    protected $attributes = ['resit' => false];

    protected function casts(): array
    {
        return [
            'resit' => 'boolean',
            'decided_at' => 'datetime',
            'told_at' => 'datetime',
            'replied_at' => 'datetime',
            'final_at' => 'datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function warning(): BelongsTo
    {
        return $this->belongsTo(StudentWarning::class);
    }

    public function isFinal(): bool
    {
        return $this->final_at !== null;
    }

    /** The student may reply once, to what they were told, until it is final. */
    public function awaitsReply(): bool
    {
        return $this->told_at !== null && $this->reply === null && ! $this->isFinal();
    }

    public function label(): string
    {
        return self::OUTCOMES[$this->outcome] ?? ucfirst($this->outcome);
    }
}
