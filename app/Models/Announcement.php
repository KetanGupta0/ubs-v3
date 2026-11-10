<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['audience' => 'batch', 'is_pinned' => false];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'is_pinned' => 'boolean'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /**
     * Everything a student on these batches and courses should see.
     *
     * A course wide announcement reaches every batch of that course, which is
     * the point of having the distinction at all.
     *
     * A notice addressed to one student reaches that student only. It carries
     * no batch or course, so none of the other branches can pick it up for
     * anybody else.
     *
     * @param  array<int, int>  $batchIds
     * @param  array<int, int>  $courseIds
     */
    public function scopeFor(Builder $query, array $batchIds, array $courseIds, ?int $studentId = null): Builder
    {
        return $query->published()->where(function (Builder $inner) use ($batchIds, $courseIds, $studentId) {
            $inner->where(fn (Builder $q) => $q->whereNull('user_id')->whereIn('batch_id', $batchIds))
                ->orWhere(fn (Builder $q) => $q->whereNull('user_id')->whereNull('batch_id')->whereIn('course_id', $courseIds))
                ->orWhere(fn (Builder $q) => $q->whereNull('user_id')->whereNull('batch_id')->whereNull('course_id')->where('audience', 'everyone'));

            if ($studentId !== null) {
                $inner->orWhere(fn (Builder $q) => $q->where('audience', 'student')->where('user_id', $studentId));
            }
        });
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
