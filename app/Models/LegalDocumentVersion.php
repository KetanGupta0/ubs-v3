<?php

namespace App\Models;

use App\Support\Markdown;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One version of a legal document.
 *
 * A draft can be edited and thrown away. Once published it is a record of
 * what people agreed to, so it is never changed and never deleted: the model
 * refuses both, whichever code path asks. A change is a new version.
 */
class LegalDocumentVersion extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::DRAFT];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version) {
            if ($version->getOriginal('status') === self::PUBLISHED) {
                throw new LogicException('A published version of a legal document is never changed. Write a new version.');
            }
        });

        static::deleting(function (self $version) {
            if ($version->getOriginal('status') === self::PUBLISHED) {
                throw new LogicException('A published version of a legal document is never deleted.');
            }
        });

        static::saved(fn () => LegalDocument::forgetPublished());
        static::deleted(fn () => LegalDocument::forgetPublished());
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    public function publish(User $by): void
    {
        $this->forceFill([
            'status' => self::PUBLISHED,
            'published_at' => now(),
            'published_by' => $by->id,
        ])->save();
    }

    public function html(): string
    {
        return Markdown::html($this->body);
    }

    /** The address a published version can be read at, for good. */
    public function path(): string
    {
        return "/legal/{$this->document->key}/versions/{$this->version}";
    }
}
