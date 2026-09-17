<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A private word with a student who is not paying attention.
 *
 * Three levels, in order, and the ladder is climbed rather than jumped: a
 * notice, then a warning, then telling a guardian. Every one carries a reason
 * and the name of whoever raised it, because escalating somebody on the basis
 * of "they seemed distracted once" is not defensible three weeks later when a
 * parent asks.
 *
 * Nothing here is ever shown to another student.
 */
class StudentWarning extends Model
{
    public const LEVELS = ['notice', 'warning', 'escalation'];

    protected $guarded = ['id'];

    protected $attributes = ['level' => 'notice'];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'guardian_notified_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeUnacknowledged(Builder $query): Builder
    {
        return $query->whereNull('acknowledged_at')->whereNull('resolved_at');
    }

    public function levelLabel(): string
    {
        return match ($this->level) {
            'notice' => 'A quiet word',
            'warning' => 'Formal warning',
            default => 'Escalated',
        };
    }

    /**
     * The next rung, given what this student already has on this batch.
     *
     * Read by the trainer's screen so the ladder is climbed rather than
     * skipped. A first offence going straight to a guardian is how a student
     * stops telling you anything.
     */
    public static function nextLevelFor(User|int $user, ?int $batchId = null): string
    {
        $highest = static::query()
            ->where('user_id', $user instanceof User ? $user->id : $user)
            ->when($batchId, fn (Builder $query) => $query->where('batch_id', $batchId))
            ->unresolved()
            ->pluck('level')
            ->map(fn (string $level) => array_search($level, self::LEVELS, true))
            ->max();

        return $highest === null || $highest === false
            ? 'notice'
            : self::LEVELS[min($highest + 1, count(self::LEVELS) - 1)];
    }
}
