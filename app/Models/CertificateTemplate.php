<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The look of a course's certificates: background, wording and signatory,
 * set into one fixed layout. See the migration for why it is not a canvas.
 */
class CertificateTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'show_grade' => 'boolean',
            'show_qr' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * The template a new certificate on this course is issued with: the
     * course's own active one, else the active default, else none (the
     * built-in design).
     */
    public static function forCourse(?int $courseId): ?self
    {
        $own = $courseId === null ? null : static::query()
            ->where('course_id', $courseId)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        return $own ?? static::query()
            ->whereNull('course_id')
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }
}
