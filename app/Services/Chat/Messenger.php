<?php

namespace App\Services\Chat;

use App\Events\Chat\MessageRead;
use App\Events\Chat\MessageRemoved;
use App\Events\Chat\MessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReceipt;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sending, reading and taking back a message.
 *
 * The order inside `send` is the point: the message is stored, the conversation
 * is stamped, receipts are written, and only then is anybody told over a
 * socket. A websocket layer that is down should cost a message its liveness,
 * never its existence — the fallback poll picks it up on the next tick and the
 * sender never sees a failure for something that did in fact save.
 */
class Messenger
{
    public function __construct(
        protected MediaStore $media,
        protected Rooms $rooms,
    ) {}

    public function sendText(Conversation $conversation, User $sender, string $body, ?int $replyToId = null): Message
    {
        $body = trim($body);

        if ($body === '') {
            throw new RuntimeException('There is nothing to send.');
        }

        return $this->store($conversation, $sender, [
            'kind' => 'text',
            'body' => $body,
            'reply_to_id' => $this->replyWithin($conversation, $replyToId),
        ]);
    }

    public function sendImage(
        Conversation $conversation,
        User $sender,
        UploadedFile $file,
        ?string $caption = null,
        ?int $replyToId = null,
    ): Message {
        $stored = $this->media->storeImage($file, $conversation->id);

        return $this->store($conversation, $sender, [
            'kind' => 'image',
            'body' => $caption ? trim($caption) : null,
            'media_path' => $stored['path'],
            'media_mime' => $stored['mime'],
            'media_size' => $stored['size'],
            'media_meta' => $stored['meta'],
            'reply_to_id' => $this->replyWithin($conversation, $replyToId),
        ]);
    }

    /** @param  array<string, mixed>  $recorded  duration and peaks, from the recorder */
    public function sendAudio(
        Conversation $conversation,
        User $sender,
        UploadedFile $file,
        array $recorded = [],
        ?int $replyToId = null,
    ): Message {
        $stored = $this->media->storeAudio($file, $conversation->id, $recorded);

        return $this->store($conversation, $sender, [
            'kind' => 'audio',
            'media_path' => $stored['path'],
            'media_mime' => $stored['mime'],
            'media_size' => $stored['size'],
            'media_meta' => $stored['meta'],
            'reply_to_id' => $this->replyWithin($conversation, $replyToId),
        ]);
    }

    /**
     * Mark everything this person can see as read, up to now.
     *
     * Their own messages are skipped: nobody reads their own message, and a
     * receipt saying otherwise would make the tick meaningless.
     */
    public function markRead(Conversation $conversation, User $reader): array
    {
        $participant = $conversation->participantFor($reader);

        $unread = $conversation->messages()
            ->whereNot('sender_id', $reader->id)
            ->when(
                $participant?->last_read_at,
                fn ($query) => $query->where('sent_at', '>', $participant->last_read_at),
            )
            ->pluck('id');

        $participant?->forceFill(['last_read_at' => now()])->save();

        if ($unread->isEmpty()) {
            return [];
        }

        MessageReceipt::query()
            ->whereIn('message_id', $unread)
            ->where('user_id', $reader->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        /*
         * A member of staff who opens a client's thread has read it, whether
         * or not they have ever replied in it. They are not a participant
         * until they do, so the receipt they need does not exist yet — it is
         * written here rather than assumed, because a client watching for a
         * second tick is asking "has anybody looked at this", and the honest
         * answer is yes.
         */
        $missing = $unread->diff(
            MessageReceipt::query()
                ->whereIn('message_id', $unread)
                ->where('user_id', $reader->id)
                ->pluck('message_id'),
        );

        if ($missing->isNotEmpty()) {
            MessageReceipt::query()->insert($missing->map(fn (int $messageId) => [
                'message_id' => $messageId,
                'user_id' => $reader->id,
                'delivered_at' => now(),
                'read_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());
        }

        $this->announce(new MessageRead($conversation->id, $reader, $unread->all()));

        return $unread->all();
    }

    /** Somebody's client has the message, whether or not they have looked at it. */
    public function markDelivered(Conversation $conversation, User $recipient): void
    {
        MessageReceipt::query()
            ->whereIn('message_id', $conversation->messages()->select('id'))
            ->where('user_id', $recipient->id)
            ->whereNull('delivered_at')
            ->update(['delivered_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Take a message back.
     *
     * Only the sender, and only their own. An administrator can remove
     * somebody else's from a batch group, because a group room needs a way to
     * deal with something that should not be there.
     */
    public function remove(Message $message, User $actor): void
    {
        $ownIt = $message->sender_id === $actor->id;
        $moderating = $actor->isAdmin() && $actor->hasPermission('chat.reply');

        abort_unless($ownIt || $moderating, 403);

        // The file goes, the row stays. The bubble then says it was removed
        // rather than leaving a hole in a conversation somebody was quoting.
        $this->media->delete($message->media_path);

        $message->forceFill(['media_path' => null])->save();
        $message->delete();

        $this->announce(new MessageRemoved($message->conversation_id, $message->id));
    }

    /* ----------------------------------------------------------- internals */

    /** @param  array<string, mixed>  $attributes */
    protected function store(Conversation $conversation, User $sender, array $attributes): Message
    {
        // Somebody who replies is in the room from then on, which is how a
        // member of staff picks up read receipts and presence without every
        // administrator being a participant in every thread.
        $this->rooms->join($conversation, $sender, $sender->isAdmin() ? 'staff' : 'member');

        $message = DB::transaction(function () use ($conversation, $sender, $attributes) {
            $message = Message::query()->create([
                ...$attributes,
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'sent_at' => now(),
            ]);

            $conversation->forceFill([
                'last_message_at' => $message->sent_at,
                'last_message_preview' => str($message->preview())->limit(150)->toString(),
            ])->save();

            $this->openReceipts($conversation, $message, $sender);

            // The sender has, by definition, read their own message.
            $conversation->participantFor($sender)?->forceFill(['last_read_at' => now()])->save();

            return $message;
        });

        $this->announce(new MessageSent($message));

        return $message;
    }

    /** A receipt per person who is meant to see it, so a tick has something to fill in. */
    protected function openReceipts(Conversation $conversation, Message $message, User $sender): void
    {
        $recipients = $conversation->participants()
            ->whereNull('left_at')
            ->whereNot('user_id', $sender->id)
            ->pluck('user_id');

        if ($recipients->isEmpty()) {
            return;
        }

        MessageReceipt::query()->insert($recipients->map(fn (int $userId) => [
            'message_id' => $message->id,
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());
    }

    /** A reply has to be to something in the same room. */
    protected function replyWithin(Conversation $conversation, ?int $replyToId): ?int
    {
        if (! $replyToId) {
            return null;
        }

        return $conversation->messages()->whereKey($replyToId)->value('id');
    }

    /**
     * Tell the room, and do not let the telling break the doing.
     *
     * Reverb being unreachable is an operational problem, not a reason for a
     * person's message to fail. It is logged and the poll covers for it.
     */
    protected function announce(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            Log::warning('Chat broadcast failed; clients will pick this up by polling.', [
                'event' => $event::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
