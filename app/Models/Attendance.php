<?php

namespace App\Models;

use App\Services\Lms\Progress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    public const STATUSES = ['present', 'late', 'absent', 'excused'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'absent'];

    /** On a course with no lessons, progress is the classes attended, so a mark moves it. */
    protected static function booted(): void
    {
        static::saved(function (Attendance $mark) {
            if ($mark->wasRecentlyCreated || $mark->wasChanged('status')) {
                app(Progress::class)->recalculateForMark($mark);
            }
        });

        static::deleted(fn (Attendance $mark) => app(Progress::class)->recalculateForMark($mark));
    }

    protected function casts(): array
    {
        return ['marked_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    /** Late still counts as attended. Being told otherwise helps nobody turn up. */
    public function scopeCounted(Builder $query): Builder
    {
        return $query->whereIn('status', ['present', 'late']);
    }

    public function counts(): bool
    {
        return in_array($this->status, ['present', 'late', 'excused'], true);
    }
}
