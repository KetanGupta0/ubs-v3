<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class QuizAttempt extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['attempt_number' => 1, 'score' => 0, 'total_marks' => 0, 'percent' => 0];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'percent' => 'decimal:2',
            'passed' => 'boolean',
            'needs_review' => 'boolean',
            'question_ids' => 'array',
            'consented_at' => 'datetime',
            'seen_at' => 'datetime',
            'live_state' => 'array',
            'option_orders' => 'array',
            'current_index' => 'integer',
            'flags' => 'array',
            'flagged_at' => 'datetime',
        ];
    }

    /** The sitting a proctored attempt was started in. */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(QuizSlot::class, 'quiz_slot_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function isProctored(): bool
    {
        return in_array($this->proctoring, ['lockdown', 'live'], true);
    }

    public function events(): HasMany
    {
        return $this->hasMany(QuizAttemptEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * The questions on this attempt's paper, in its order: drawn and shuffled
     * when it started. An attempt from before pools had every question, in the
     * quiz's order.
     *
     * @return Collection<int, Question>
     */
    public function paper()
    {
        $questions = $this->quiz->questions()->get()->keyBy('id');

        if (empty($this->question_ids)) {
            return $questions->values();
        }

        return collect($this->question_ids)->map(fn ($id) => $questions->get($id))->filter()->values();
    }

    /** The option order this attempt shows a question in: original indexes, in display order. */
    public function optionOrderFor(Question $question): ?array
    {
        return $this->option_orders[(string) $question->id] ?? $this->option_orders[$question->id] ?? null;
    }

    public function isFlagged(): bool
    {
        return ! empty($this->flags);
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class);
    }

    public function isOpen(): bool
    {
        return $this->submitted_at === null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function secondsRemaining(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }
}
