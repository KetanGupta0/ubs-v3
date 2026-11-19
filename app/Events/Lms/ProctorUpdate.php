<?php

namespace App\Events\Lms;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something a proctor watching a quiz should see now (§9.1a): a student left
 * full screen, tried to copy, answered, came back, finished. Sent straight
 * away, because a violation shown a minute late is a violation missed; the
 * console polls as well, so a socket that is down costs only the speed.
 */
class ProctorUpdate implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array<string, mixed>  $tile  What the console shows for this attempt. */
    public function __construct(
        public int $quizId,
        public array $tile,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('quiz-proctor.'.$this->quizId);
    }

    public function broadcastAs(): string
    {
        return 'attempt.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['tile' => $this->tile];
    }
}
