<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's review of a course.
 *
 * Nobody but the student and staff sees it until it is approved, and it
 * reaches the public site only when it is approved and the student agreed.
 * A real review with consent is proof; anything else would be the kind of
 * invented social proof the public site refuses to carry.
 */
class CourseReview extends Model
{
    public const STATUSES = ['pending', 'approved', 'hidden'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending', 'may_publish' => false];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'may_publish' => 'boolean',
            'replied_at' => 'datetime',
            'moderated_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function replier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }

    /** Approved, and the student said it may be shown publicly. */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('status', 'approved')->where('may_publish', true);
    }

    /** "Asha V." — enough to be a real person, not enough to find them. */
    public function publicName(): string
    {
        $name = trim((string) $this->student?->name);
        $parts = preg_split('/\s+/', $name) ?: [];

        if (count($parts) < 2) {
            return $name ?: 'A student';
        }

        return $parts[0].' '.mb_strtoupper(mb_substr(end($parts), 0, 1)).'.';
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->publicName(),
            'rating' => $this->rating,
            'title' => $this->title,
            'body' => $this->body,
            'reply' => $this->reply,
            'date' => $this->created_at?->format('M Y'),
        ];
    }
}
