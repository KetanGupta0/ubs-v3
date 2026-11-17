<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** A call or a meeting with a client, and its minutes (CL11). */
class Meeting extends Model
{
    public const STATUSES = ['scheduled', 'held', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'duration_minutes' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Every version of the minutes, oldest first. */
    public function minutes(): HasMany
    {
        return $this->hasMany(MeetingMinutes::class)->orderBy('version');
    }

    public function scopeForClient(Builder $query, User $account): Builder
    {
        return $query->where('client_id', $account->id);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', 'scheduled')->where('starts_at', '>=', now()->subMinutes(30));
    }

    public function endsAt(): Carbon
    {
        return $this->starts_at->copy()->addMinutes($this->duration_minutes);
    }

    /** The newest version the client can read. */
    public function publishedMinutes(): ?MeetingMinutes
    {
        return $this->minutes->filter(fn (MeetingMinutes $minutes) => $minutes->published_at !== null)->last();
    }

    /** The version being written, if any. */
    public function draftMinutes(): ?MeetingMinutes
    {
        return $this->minutes->first(fn (MeetingMinutes $minutes) => $minutes->published_at === null);
    }

    public function whenLabel(): string
    {
        return $this->starts_at->format('D j M Y, g:i a').' to '.$this->endsAt()->format('g:i a');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'scheduled' => $this->starts_at->isPast() ? 'Waiting for minutes' : 'Scheduled',
            'held' => 'Minutes published',
            'cancelled' => 'Cancelled',
            default => ucfirst($this->status),
        };
    }
}
