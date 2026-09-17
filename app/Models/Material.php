<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file or a link attached to a lesson.
 *
 * `is_downloadable` false means viewable in the browser but not saveable, which
 * is a request we get for slide decks. It is a deterrent rather than a lock:
 * anything a browser can render can be captured, and pretending otherwise would
 * be selling somebody a guarantee we cannot keep.
 */
class Material extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['is_downloadable' => true, 'sort_order' => 0, 'size' => 0];

    protected function casts(): array
    {
        return ['is_downloadable' => 'boolean', 'size' => 'integer'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isLink(): bool
    {
        return blank($this->path) && filled($this->external_url);
    }

    public function sizeLabel(): ?string
    {
        if ($this->isLink() || $this->size === 0) {
            return null;
        }

        return match (true) {
            $this->size >= 1048576 => round($this->size / 1048576, 1).' MB',
            $this->size >= 1024 => round($this->size / 1024).' KB',
            default => $this->size.' B',
        };
    }

    public function kind(): string
    {
        return match (true) {
            $this->isLink() => 'link',
            str_starts_with((string) $this->mime, 'image/') => 'image',
            str_starts_with((string) $this->mime, 'video/') => 'video',
            $this->mime === 'application/pdf' => 'pdf',
            str_contains((string) $this->mime, 'zip') => 'archive',
            str_contains((string) $this->mime, 'presentation') => 'slides',
            str_contains((string) $this->mime, 'sheet') => 'sheet',
            default => 'file',
        };
    }
}
