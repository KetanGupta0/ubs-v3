<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A file belonging to a client.
 *
 * A new version is a new row pointing at the one it replaces, rather than an
 * overwrite. The old file stays downloadable, which is the only version history
 * worth having: one where the previous version still exists.
 */
class Document extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'visible_to_client' => 'boolean',
            'is_current' => 'boolean',
            'client_viewed_at' => 'datetime',
            'size' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function scopeVisibleToClient(Builder $query): Builder
    {
        return $query->where('visible_to_client', true);
    }

    public function sizeLabel(): string
    {
        $bytes = $this->size;

        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }

    /** Previewable in a browser tab rather than only downloadable. */
    public function isPreviewable(): bool
    {
        return in_array($this->mime, [
            'application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'image/gif', 'text/plain',
        ], true);
    }

    public function kind(): string
    {
        return match (true) {
            str_starts_with((string) $this->mime, 'image/') => 'image',
            $this->mime === 'application/pdf' => 'pdf',
            str_contains((string) $this->mime, 'spreadsheet'), str_contains((string) $this->mime, 'excel') => 'sheet',
            str_contains((string) $this->mime, 'word'), str_contains((string) $this->mime, 'document') => 'doc',
            str_contains((string) $this->mime, 'zip') => 'archive',
            default => 'file',
        };
    }
}
