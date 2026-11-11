<?php

namespace App\Models;

use App\Support\ImageUpload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A picture in the media library.
 *
 * Every one is re-encoded on the way in, which is what strips the camera and
 * location details a phone photo carries, and capped in width so a forty
 * megabyte original is never what a visitor downloads. A PNG stays a PNG so a
 * transparent logo keeps its transparency; everything else becomes a JPEG.
 * It always has a description, because a picture somebody cannot see is
 * otherwise a hole in the page.
 */
class Media extends Model
{
    public const DIRECTORY = 'media';

    public const MAX_WIDTH = 1600;

    /** A stored library path, and nothing else that might pass for one. */
    public const PATH_PATTERN = '#^media/[0-9a-f-]{36}\.(jpg|png)$#';

    protected $table = 'media';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['width' => 'integer', 'height' => 'integer', 'size' => 'integer'];
    }

    public static function upload(UploadedFile $file, string $alt, ?User $by, string $field = 'file'): self
    {
        $as = $file->getMimeType() === 'image/png' ? 'png' : 'jpg';
        $path = ImageUpload::store($file, 'public', self::DIRECTORY, self::MAX_WIDTH, $as, $field);
        $bytes = Storage::disk('public')->get($path);
        [$width, $height] = getimagesizefromstring($bytes);

        return static::query()->create([
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'alt' => trim($alt),
            'width' => $width,
            'height' => $height,
            'size' => strlen($bytes),
            'uploaded_by' => $by?->id,
        ]);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    /**
     * Pages that show this picture, drafts included.
     *
     * Matched on the file's unique name rather than its path, because the
     * blocks are stored as JSON with the slash escaped.
     */
    public function pages(): Builder
    {
        return Page::query()->where('blocks', 'like', '%'.pathinfo($this->path, PATHINFO_FILENAME).'%');
    }

    /** What the picker and the page editor need. */
    public function toPickerArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'url' => $this->url(),
            'alt' => $this->alt,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
