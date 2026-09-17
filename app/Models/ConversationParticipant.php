<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person in one room.
 *
 * `last_read_at` is the whole unread mechanism: everything sent after it is
 * unread. Counting receipts would give the same answer at a much higher price,
 * and "since I last looked" is what somebody means by unread anyway.
 */
class ConversationParticipant extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['role' => 'member'];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'last_read_at' => 'datetime',
            'left_at' => 'datetime',
            'is_muted' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * How many messages this person has not seen.
     *
     * Their own messages never count, because nobody has unread messages from
     * themselves.
     */
    public function unreadCount(): int
    {
        return $this->conversation->messages()
            ->whereNot('sender_id', $this->user_id)
            ->when(
                $this->last_read_at,
                fn ($query) => $query->where('sent_at', '>', $this->last_read_at),
            )
            ->count();
    }
}
