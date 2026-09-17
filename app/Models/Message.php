<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One message.
 *
 * Text, image or audio, and nothing else. The column is an enum, so a fourth
 * kind cannot be introduced by a controller alone.
 *
 * Deleted messages are soft deleted and rendered as "this message was removed"
 * rather than vanishing, because a hole in a conversation somebody was quoting
 * is worse than an honest gap.
 */
class Message extends Model
{
    use SoftDeletes;

    public const KINDS = ['text', 'image', 'audio'];

    protected $guarded = ['id'];

    protected $attributes = ['kind' => 'text', 'media_size' => 0];

    protected function casts(): array
    {
        return [
            'media_meta' => 'array',
            'sent_at' => 'datetime',
            'edited_at' => 'datetime',
            'media_size' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id')->withTrashed();
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(MessageReceipt::class);
    }

    public function hasMedia(): bool
    {
        return $this->kind !== 'text' && filled($this->media_path);
    }

    /** Seconds, for audio. */
    public function duration(): ?int
    {
        return $this->media_meta['duration'] ?? null;
    }

    /** The bars a waveform is drawn from, computed once at upload. */
    public function peaks(): array
    {
        return $this->media_meta['peaks'] ?? [];
    }

    /**
     * The one line a conversation list shows.
     *
     * An image or a voice note has no text to preview, so it is named for what
     * it is rather than shown as an empty row.
     */
    public function preview(): string
    {
        if ($this->trashed()) {
            return 'Message removed';
        }

        return match ($this->kind) {
            'image' => $this->body ? '📷 '.$this->body : '📷 Photo',
            'audio' => '🎤 Voice message'.($this->duration() ? ' · '.$this->durationLabel() : ''),
            default => (string) $this->body,
        };
    }

    public function durationLabel(): ?string
    {
        $seconds = $this->duration();

        if ($seconds === null) {
            return null;
        }

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    public function scopeInOrder(Builder $query): Builder
    {
        return $query->orderBy('sent_at')->orderBy('id');
    }
}
