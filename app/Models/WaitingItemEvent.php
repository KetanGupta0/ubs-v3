<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step in a "waiting on you" item's life. Never changed afterwards. */
class WaitingItemEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'document_ids' => 'array',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(WaitingItem::class, 'waiting_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
