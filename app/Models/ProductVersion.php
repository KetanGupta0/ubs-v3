<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;

/**
 * One release of a product's source code, kept for good (X16).
 *
 * The file lives on the private disk and is only ever handed out through a
 * signed link that expires. A version is withdrawn rather than deleted once
 * somebody may have it, so what they downloaded can still be named.
 */
class ProductVersion extends Model
{
    protected $guarded = ['id'];

    /** The column defaults, on the instance too: a default is never written back to the model that inserted the row. */
    protected $attributes = ['is_available' => true];

    protected function casts(): array
    {
        return [
            'released_on' => 'date',
            'size' => 'integer',
            'is_available' => 'boolean',
        ];
    }

    public function solution(): BelongsTo
    {
        return $this->belongsTo(Solution::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    /** Newest first: by release date, then by when it was uploaded. */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('released_on')->orderByDesc('id');
    }

    public function sizeLabel(): string
    {
        return Number::fileSize($this->size, maxPrecision: 1);
    }

    /** The name a download is saved under. */
    public function downloadName(): string
    {
        return str($this->solution?->slug ?? 'product')->append('-', $this->version, '.zip')->toString();
    }
}
