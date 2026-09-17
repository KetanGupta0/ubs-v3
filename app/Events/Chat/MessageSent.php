<?php

namespace App\Events\Chat;

use App\Models\Message;
use App\Support\Chat\MessagePayload;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message arrived.
 *
 * Broadcast now rather than queued. Everywhere else in this codebase a queued
 * job is the right answer, but a chat message that waits for a worker is not a
 * chat message. The send path stores first and broadcasts second, so a socket
 * layer that is down costs the message its liveness, never its existence.
 */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('conversation.'.$this->message->conversation_id);
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['message' => MessagePayload::for($this->message->loadMissing(['sender', 'replyTo.sender']))];
    }
}
