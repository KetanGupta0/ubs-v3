<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sitting of a proctored quiz (§9.1a): when it may be started, for one
 * batch or everybody it is set for, and so when somebody is watching. A live
 * stream at 2 a.m. with nobody watching deters nobody and still costs the
 * student their privacy. No attempt started in a sitting runs past its end.
 */
class QuizSlot extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /** Sittings somebody on this batch may use: their batch's, and those for everybody. */
    public function scopeForBatch(Builder $query, ?int $batchId): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->whereNull($this->qualifyColumn('batch_id'))
            ->when($batchId, fn (Builder $either) => $either->orWhere($this->qualifyColumn('batch_id'), $batchId)));
    }

    public function isOpen(): bool
    {
        return $this->starts_at->isPast() && $this->ends_at->isFuture();
    }

    /** "Thu 21 Nov, 10:00 am to 11:00 am", the way a student reads it. */
    public function window(): string
    {
        $sameDay = $this->starts_at->isSameDay($this->ends_at);

        return $this->starts_at->format('D j M, g:i a').' to '.$this->ends_at->format($sameDay ? 'g:i a' : 'D j M, g:i a');
    }
}
