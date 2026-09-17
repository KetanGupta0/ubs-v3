<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One room.
 *
 * Either a client's own thread with us, optionally about one project, or a
 * batch group where a cohort talks together. Everything else about a room —
 * who may read it, what it is called, who is in it — follows from that.
 */
class Conversation extends Model
{
    public const TYPES = ['client_direct', 'batch_group'];

    protected $guarded = ['id'];

    protected $attributes = ['type' => 'client_direct'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'is_archived' => 'boolean',
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isGroup(): bool
    {
        return $this->type === 'batch_group';
    }

    /**
     * What this room is called on screen.
     *
     * A batch group is named after the batch. A client thread is named after
     * the project when there is one, because a client with four projects needs
     * to know which thread they are in before they type.
     */
    public function displayTitle(): string
    {
        if ($this->title) {
            return $this->title;
        }

        if ($this->isGroup()) {
            return $this->batch?->name ?? 'Batch';
        }

        return $this->project?->name
            ?? ($this->client?->name ? $this->client->name.' — general' : 'Conversation');
    }

    public function participantFor(User|int $user): ?ConversationParticipant
    {
        $id = $user instanceof User ? $user->id : $user;

        return $this->participants->firstWhere('user_id', $id)
            ?? $this->participants()->where('user_id', $id)->first();
    }

    /**
     * Whether this person may open this room at all.
     *
     * Membership is for the people the room is about: the client, or the
     * students on the batch. Staff reach it by permission instead, because
     * putting every administrator into every thread would mean a participant
     * row per staff member per client and an unread badge nobody asked for.
     * The check is one method so the page, the API and the broadcast channel
     * cannot drift apart on who is allowed in.
     */
    public function canBeReadBy(User $user): bool
    {
        if ($this->includes($user)) {
            return true;
        }

        return $user->isAdmin() && $user->hasPermission('chat.reply');
    }

    public function includes(User|int $user): bool
    {
        $id = $user instanceof User ? $user->id : $user;

        return $this->participants()
            ->where('user_id', $id)
            ->whereNull('left_at')
            ->exists();
    }

    /** Rooms this person is actually in. */
    public function scopeForParticipant(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->id : $user;

        return $query->whereHas(
            'participants',
            fn (Builder $participants) => $participants->where('user_id', $id)->whereNull('left_at'),
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }
}
