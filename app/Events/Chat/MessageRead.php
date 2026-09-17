<?php

namespace App\Events\Chat;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Somebody has the thread open and has now seen these messages. */
class MessageRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param  array<int, int>  $messageIds */
    public function __construct(
        public int $conversationId,
        public User $reader,
        public array $messageIds,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('conversation.'.$this->conversationId);
    }

    public function broadcastAs(): string
    {
        return 'message.read';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'userId' => $this->reader->id,
            'name' => $this->reader->name,
            'messageIds' => $this->messageIds,
            'readAt' => now()->toIso8601String(),
        ];
    }
}
