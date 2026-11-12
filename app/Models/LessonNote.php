<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's own note on a lesson, and whether they bookmarked it.
 *
 * Private to the student. There is no screen, report or export that shows one
 * to anybody else, staff included, and "view as" does not open them either.
 */
class LessonNote extends Model
{
    public const MAX_LENGTH = 10000;

    protected $fillable = ['user_id', 'lesson_id', 'course_id', 'body', 'is_bookmarked'];

    protected function casts(): array
    {
        return ['is_bookmarked' => 'boolean'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
