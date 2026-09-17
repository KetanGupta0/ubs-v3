<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| A socket is another door into the same rooms, so it asks the same question
| the page asks: may this person read this conversation? `canBeReadBy` is that
| question, in one place, and this file is deliberately thin so the two answers
| cannot drift apart.
|
*/

Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
    $conversation = Conversation::query()->find($conversationId);

    if (! $conversation || ! $conversation->canBeReadBy($user)) {
        return false;
    }

    // What a presence channel returns is what every other member of the room
    // gets to see about this person, so it carries a name and a face and
    // nothing else. An email address is not needed to draw a typing indicator.
    return [
        'id' => $user->id,
        'name' => $user->name,
        'avatarUrl' => $user->avatar_url,
        'role' => $user->role->value,
    ];
});
