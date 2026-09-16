<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An enquiry from the public site.
 */
class Lead extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (Lead $lead) {
            $lead->reference ??= self::nextReference();
        });
    }

    protected function casts(): array
    {
        return [];
    }

    public function solution(): BelongsTo
    {
        return $this->belongsTo(Solution::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['new', 'contacted', 'qualified']);
    }

    /**
     * A short human reference the visitor can quote back at us.
     *
     * Sequential within the month rather than random, so a burst of enquiries
     * is obvious when reading the inbox.
     */
    public static function nextReference(): string
    {
        $prefix = 'UBS-'.now()->format('ym');

        $last = self::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** What the enquiry was about, as a readable line. */
    public function subject(): string
    {
        return match (true) {
            (bool) $this->solution_id => 'Solution: '.($this->solution?->title ?? 'unknown'),
            (bool) $this->service_id => 'Service: '.($this->service?->title ?? 'unknown'),

            // An internship and a course share a table, so the label comes from
            // what the offering actually is rather than from the table it is in.
            (bool) $this->course_id => ($this->course?->isInternship() ? 'Internship: ' : 'Training: ')
                .($this->course?->title ?? 'unknown'),

            $this->interest === 'college' => 'College tie-up'
                .($this->college_name ? ': '.$this->college_name : ''),

            default => 'General enquiry',
        };
    }
}
