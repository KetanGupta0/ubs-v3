<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One edited field of a fixed page: what is live, and a draft if there is one.
 * See App\Support\PageCopy.
 */
class PageCopyEntry extends Model
{
    protected $table = 'page_copy';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['has_draft' => 'boolean', 'published_at' => 'datetime'];
    }
}
