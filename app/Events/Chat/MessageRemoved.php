<?php

namespace App\Events\Chat;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message was taken back.
 *
 * The row stays and the bubble stays, saying it was removed. A hole where a
 * message used to be is worse than an honest gap, especially when somebody
 * else has quoted it.
 */
class MessageRemoved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $conversationId, public int $messageId) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('conversation.'.$this->conversationId);
    }

    public function broadcastAs(): string
    {
        return 'message.removed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['messageId' => $this->messageId];
    }
}
