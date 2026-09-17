<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether one person received and read one message.
 *
 * Two ticks and a blue tick, in other words. Delivered is written when the
 * message reaches somebody's open client; read when the thread is actually on
 * screen. A receipt that claims read for a notification nobody opened would be
 * a lie of exactly the kind people rely on these marks not to tell.
 */
class MessageReceipt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
