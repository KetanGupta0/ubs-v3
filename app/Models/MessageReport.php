<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member saying a message in their group should not be there.
 *
 * It goes to the people who run the group, who remove it, keep its sender
 * from posting for a while, or dismiss the report. A person decides; nothing
 * is taken down because of a report alone.
 */
class MessageReport extends Model
{
    public const STATUSES = [
        'open' => 'Waiting',
        'actioned' => 'Dealt with',
        'dismissed' => 'Dismissed',
    ];

    protected $fillable = ['message_id', 'conversation_id', 'reporter_id', 'reason', 'status', 'handled_by', 'handled_at'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class)->withTrashed();
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
