<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One class, on Google Meet.
 *
 * The join button opens shortly before the scheduled time rather than always,
 * because a link that is live all week gets clicked on a Tuesday by somebody
 * who then sits in an empty room and assumes the class was cancelled.
 */
class LiveSession extends Model
{
    /** Minutes before the start time that the room opens. */
    public const OPENS_EARLY = 15;

    /** Minutes after the end time that the link stays clickable. */
    public const STAYS_OPEN = 30;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'scheduled', 'duration_minutes' => 90];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function warnings(): HasMany
    {
        return $this->hasMany(StudentWarning::class);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('scheduled_at', '>=', now()->subHours(2))
            ->whereNot('status', 'cancelled')
            ->orderBy('scheduled_at');
    }

    public function scopePast(Builder $query): Builder
    {
        return $query->where('scheduled_at', '<', now())->orderByDesc('scheduled_at');
    }

    public function endsAt(): Carbon
    {
        return $this->scheduled_at->copy()->addMinutes($this->duration_minutes);
    }

    public function isJoinable(): bool
    {
        if ($this->status === 'cancelled' || blank($this->link())) {
            return false;
        }

        return now()->between(
            $this->scheduled_at->copy()->subMinutes(self::OPENS_EARLY),
            $this->endsAt()->addMinutes(self::STAYS_OPEN),
        );
    }

    /** The session's own room, or the batch's standing one. */
    public function link(): ?string
    {
        return $this->meet_link ?: $this->batch?->meet_link;
    }

    public function isLive(): bool
    {
        return now()->between($this->scheduled_at, $this->endsAt());
    }

    public function attendancePercent(): int
    {
        $total = $this->attendances()->count();

        if ($total === 0) {
            return 0;
        }

        $present = $this->attendances()->whereIn('status', ['present', 'late'])->count();

        return (int) round($present / $total * 100);
    }
}
