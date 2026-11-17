<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A group's settings changed: what is pinned, whether it is in announcement
 * mode. Sent now, like every chat event; the poll carries the same settings
 * for anybody without a socket.
 */
class RoomUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array<string, mixed>  $settings */
    public function __construct(public int $conversationId, public array $settings) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('conversation.'.$this->conversationId);
    }

    public function broadcastAs(): string
    {
        return 'room.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->settings;
    }
}
