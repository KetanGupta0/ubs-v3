<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A link in one of the public site's menus. */
class SiteMenuItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(SiteMenu::class, 'site_menu_id');
    }

    /** Another site: opened in a new tab, never through the client router. */
    public function isExternal(): bool
    {
        return str_starts_with($this->href, 'https://');
    }
}
