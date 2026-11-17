<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One version of a meeting's minutes (CL11).
 *
 * Once published, what it says is permanent, whatever code path asks: a
 * correction is the next version. Only the client's answer to it, an
 * acknowledgement or a note saying what is wrong, is written afterwards.
 */
class MeetingMinutes extends Model
{
    public const FROZEN = ['meeting_id', 'version', 'attendees', 'body', 'client_actions', 'team_actions', 'published_at', 'published_by'];

    protected $table = 'meeting_minutes';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (MeetingMinutes $minutes) {
            if ($minutes->getOriginal('published_at') !== null && $minutes->isDirty(self::FROZEN)) {
                throw new LogicException('Published minutes cannot be changed. Write the next version instead.');
            }
        });

        static::deleting(function (MeetingMinutes $minutes) {
            if ($minutes->published_at !== null) {
                throw new LogicException('Published minutes cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'attendees' => 'array',
            'client_actions' => 'array',
            'team_actions' => 'array',
            'published_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'disputed_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function disputer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disputed_by');
    }

    /** Published and not yet answered by the client. */
    public function awaitsAnswer(): bool
    {
        return $this->published_at !== null && $this->acknowledged_at === null && $this->disputed_at === null;
    }
}
