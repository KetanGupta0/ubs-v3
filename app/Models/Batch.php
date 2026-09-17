<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

class Batch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'schedule' => 'array',
            'is_published' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    /* ------------------------------------------- learning management */

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(LiveSession::class)->orderBy('scheduled_at');
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn('status', ['upcoming', 'running']);
    }

    /** Students actively on this batch. */
    public function students(): HasManyThrough
    {
        return $this->hasManyThrough(User::class, Enrollment::class, 'batch_id', 'id', 'id', 'user_id');
    }

    public function activeStudentCount(): int
    {
        return $this->enrollments()->active()->count();
    }

    /** Keep seats_taken honest rather than trusting whoever last wrote to it. */
    public function recountSeats(): self
    {
        $this->forceFill(['seats_taken' => $this->activeStudentCount()])->save();

        return $this;
    }

    public function weekNumber(?Carbon $on = null): int
    {
        if (! $this->starts_on) {
            return 1;
        }

        $on ??= today();

        return max(1, (int) floor($this->starts_on->diffInWeeks($on)) + 1);
    }

    public function seatsLeft(): ?int
    {
        return $this->capacity ? max($this->capacity - $this->seats_taken, 0) : null;
    }

    public function isNearlyFull(): bool
    {
        $left = $this->seatsLeft();

        return $left !== null && $left > 0 && $left <= 5;
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'startsOn' => $this->starts_on?->toDateString(),
            'startsOnLabel' => $this->starts_on?->format('j M Y'),
            'endsOnLabel' => $this->ends_on?->format('j M Y'),
            'schedule' => $this->schedule ?? [],
            'seatsLeft' => $this->seatsLeft(),
            'nearlyFull' => $this->isNearlyFull(),
            'status' => $this->status,
        ];
    }
}
