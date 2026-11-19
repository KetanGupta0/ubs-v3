<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the AI made of a flagged attempt (§9.1, layer 3): how worried to be,
 * the evidence it points at, and the innocent explanations it weighed. Advice
 * for a person; it never changes a score or reaches the student.
 */
class QuizAiReview extends Model
{
    public const RISKS = ['none', 'low', 'medium', 'high'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'queued'];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'explanations' => 'array',
            'sent_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'quiz_attempt_id');
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['queued', 'sent'], true);
    }
}
