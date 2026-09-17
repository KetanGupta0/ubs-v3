<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question.
 *
 * `correct` never leaves the server while an attempt is open. The shape it
 * takes depends on the type: an index for a single choice, a list of indexes
 * for multiple, a boolean for true or false, and nothing at all for a short
 * answer, which a person has to mark.
 */
class Question extends Model
{
    public const TYPES = ['mcq', 'multi', 'truefalse', 'short'];

    protected $guarded = ['id'];

    protected $hidden = ['correct'];

    protected $attributes = ['type' => 'mcq', 'marks' => 1, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['options' => 'array', 'correct' => 'array'];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function isAutoMarked(): bool
    {
        return $this->type !== 'short';
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'mcq' => 'One answer',
            'multi' => 'Several answers',
            'truefalse' => 'True or false',
            default => 'Written answer',
        };
    }

    /** What a student sitting the quiz is allowed to see. */
    public function forAttempt(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'typeLabel' => $this->typeLabel(),
            'body' => $this->body,
            'options' => $this->options ?? [],
            'marks' => $this->marks,
        ];
    }
}
