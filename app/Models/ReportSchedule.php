<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A report that arrives without anybody asking.
 *
 * The recipients are addresses rather than accounts, because the person who
 * needs the monthly numbers is often an accountant or a college coordinator
 * with no login here, and making them have one to receive a PDF would be a
 * strange thing to insist on.
 */
class ReportSchedule extends Model
{
    public const CADENCES = ['daily', 'weekly', 'monthly'];

    protected $guarded = ['id'];

    protected $attributes = ['cadence' => 'monthly', 'hour' => 7, 'format' => 'pdf', 'is_active' => true];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'recipients' => 'array',
            'is_active' => 'boolean',
            'last_sent_at' => 'datetime',
            'last_failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether this is due now.
     *
     * Checked against when it last went out rather than against the clock
     * alone, so a worker that runs twice in an hour does not send twice, and
     * one that was down for a day still sends once when it comes back.
     */
    public function isDue(?Carbon $at = null): bool
    {
        $at = $at ?? now();

        if (! $this->is_active || $at->hour < $this->hour) {
            return false;
        }

        $sent = $this->last_sent_at;

        return match ($this->cadence) {
            'daily' => $sent === null || $sent->lt($at->copy()->startOfDay()),
            'weekly' => $at->dayOfWeekIso === ($this->day ?: 1)
                && ($sent === null || $sent->lt($at->copy()->startOfWeek())),
            default => $at->day === ($this->day ?: 1)
                && ($sent === null || $sent->lt($at->copy()->startOfMonth())),
        };
    }

    public function cadenceLabel(): string
    {
        return match ($this->cadence) {
            'daily' => 'Every day',
            'weekly' => 'Every '.Carbon::now()->startOfWeek()->addDays(($this->day ?: 1) - 1)->format('l'),
            default => 'Monthly, on the '.$this->ordinal($this->day ?: 1),
        };
    }

    protected function ordinal(int $number): string
    {
        $suffix = match (true) {
            in_array($number % 100, [11, 12, 13], true) => 'th',
            $number % 10 === 1 => 'st',
            $number % 10 === 2 => 'nd',
            $number % 10 === 3 => 'rd',
            default => 'th',
        };

        return $number.$suffix;
    }
}
