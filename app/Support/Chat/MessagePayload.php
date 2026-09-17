<?php

namespace App\Support\Chat;

use App\Models\Message;
use Illuminate\Support\Facades\URL;

/**
 * One shape for a message.
 *
 * The page, the poll that covers for a dead socket, the websocket broadcast and
 * the mobile API all render the same bubble, so they all read the same array.
 * Four hand written shapes would disagree within a month, and the bug would be
 * "the image is missing, but only over the socket".
 */
class MessagePayload
{
    /** How long a media link stays good for. Long enough to open, short enough to leak harmlessly. */
    public const MEDIA_MINUTES = 30;

    /** @return array<string, mixed> */
    public static function for(Message $message): array
    {
        return [
            'id' => $message->id,
            'conversationId' => $message->conversation_id,
            'kind' => $message->kind,
            'body' => $message->trashed() ? null : $message->body,
            'removed' => $message->trashed(),
            'sender' => [
                'id' => $message->sender_id,
                'name' => $message->sender?->name ?? 'Somebody',
                'avatarUrl' => $message->sender?->avatar_url,
                'role' => $message->sender?->role?->value,
            ],
            'sentAt' => $message->sent_at->toIso8601String(),
            'sentAtLabel' => $message->sent_at->format('g:i a'),
            'dayLabel' => self::dayLabel($message),
            'edited' => $message->edited_at !== null,

            // A link only exists while the media does, and only for a while.
            'mediaUrl' => $message->hasMedia() && ! $message->trashed()
                ? URL::temporarySignedRoute('chat.media', now()->addMinutes(self::MEDIA_MINUTES), [
                    'message' => $message->id,
                ])
                : null,
            'mediaMime' => $message->media_mime,
            'width' => $message->media_meta['width'] ?? null,
            'height' => $message->media_meta['height'] ?? null,
            'duration' => $message->duration(),
            'durationLabel' => $message->durationLabel(),
            'peaks' => $message->peaks(),

            'replyTo' => $message->replyTo ? [
                'id' => $message->replyTo->id,
                'sender' => $message->replyTo->sender?->name ?? 'Somebody',
                'preview' => $message->replyTo->preview(),
                'kind' => $message->replyTo->kind,
            ] : null,

            // Filled in per viewer by the controller; a broadcast cannot know
            // who is about to receive it.
            'readBy' => [],
            'deliveredTo' => 0,
        ];
    }

    /**
     * Today, Yesterday, or the date.
     *
     * Formatted on the server so every client agrees, rather than each one
     * deciding what "yesterday" means in whichever timezone the browser is set
     * to. Everything here runs on Asia/Kolkata.
     */
    protected static function dayLabel(Message $message): string
    {
        return match (true) {
            $message->sent_at->isToday() => 'Today',
            $message->sent_at->isYesterday() => 'Yesterday',
            $message->sent_at->isCurrentYear() => $message->sent_at->format('j F'),
            default => $message->sent_at->format('j F Y'),
        };
    }
}
