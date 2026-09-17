<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    public const STATUSES = ['present', 'late', 'absent', 'excused'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'absent'];

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
