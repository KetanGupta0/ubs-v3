<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A weekly sit down with an intern, written up.
 *
 * These are what the final mentor evaluation is assembled from. An evaluation
 * written from memory on the last day is a guess with a signature on it; one
 * built from eight weekly notes can be defended when a university asks how the
 * mark was reached.
 */
class MentorReview extends Model
{
    /** What a university typically wants marked, out of ten. */
    public const CRITERIA = [
        'technical' => 'Technical ability',
        'application' => 'Applying what was taught',
        'communication' => 'Communication',
        'initiative' => 'Initiative',
        'punctuality' => 'Punctuality and attendance',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reviewed_on' => 'date', 'marks' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** The average across the criteria that were actually marked. */
    public function averageMark(): ?float
    {
        $marks = collect($this->marks ?? [])->filter(fn ($value) => is_numeric($value));

        return $marks->isEmpty() ? null : round($marks->avg(), 1);
    }
}
