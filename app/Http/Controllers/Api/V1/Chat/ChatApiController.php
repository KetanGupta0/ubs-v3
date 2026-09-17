<?php

namespace App\Http\Controllers\Api\V1\Chat;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Chat\Messenger;
use App\Services\Chat\Rooms;
use App\Support\Chat\MessagePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Chat, for the mobile applications.
 *
 * The same rooms, the same rule about who may open them, and the same message
 * shape the web screens render. A phone holds its own socket connection using
 * the same Reverb credentials the browser uses; this is what it reads history
 * and posts through.
 */
class ChatApiController extends Controller
{
    public function __construct(
        protected Rooms $rooms,
        protected Messenger $messenger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isClient()) {
            $this->rooms->ensureClientRooms($user);
        }

        if ($user->isStudent()) {
            $this->rooms->ensureStudentRooms($user);
        }

        return response()->json([
            'data' => $this->rooms->visibleTo($user)->map(fn (Conversation $room) => [
                'id' => $room->id,
                'type' => $room->type,
                'title' => $room->displayTitle(),
                'preview' => $room->last_message_preview,
                'lastMessageAt' => $room->last_message_at?->toIso8601String(),
                'unread' => $room->participantFor($user)?->unreadCount() ?? 0,
            ]),
        ]);
    }

    public function messages(Request $request, int $conversation): JsonResponse
    {
        $room = $this->readable($request, $conversation);
        $user = $request->user();

        $after = (int) $request->integer('after');

        $messages = $room->messages()
            ->withTrashed()
            ->with(['sender:id,name,avatar_path,role', 'replyTo.sender:id,name'])
            ->when($after > 0, fn ($query) => $query->where('id', '>', $after))
            ->inOrder()
            ->take(100)
            ->get();

        $this->messenger->markDelivered($room, $user);

        return response()->json([
            'data' => $messages->map(fn (Message $message) => [
                ...MessagePayload::for($message),
                'mine' => $message->sender_id === $user->id,
            ]),
        ]);
    }

    public function send(Request $request, int $conversation): JsonResponse
    {
        $room = $this->readable($request, $conversation);

        $validated = $this->validatedInput($request, [
            'kind' => ['required', Rule::in(Message::KINDS)],
            'body' => ['nullable', 'string', 'max:4000'],
            'file' => ['nullable', 'file', 'max:12288'],
            'reply_to_id' => ['nullable', 'integer'],
            'duration' => ['nullable', 'numeric', 'min:0', 'max:3600'],
            'peaks' => ['nullable', 'array', 'max:200'],
            'peaks.*' => ['numeric'],
        ]);

        try {
            $message = match ($validated['kind']) {
                'image' => $this->messenger->sendImage(
                    $room,
                    $request->user(),
                    $request->file('file'),
                    $validated['body'],
                    $validated['reply_to_id'],
                ),
                'audio' => $this->messenger->sendAudio(
                    $room,
                    $request->user(),
                    $request->file('file'),
                    ['duration' => $validated['duration'], 'peaks' => $validated['peaks'] ?? []],
                    $validated['reply_to_id'],
                ),
                default => $this->messenger->sendText(
                    $room,
                    $request->user(),
                    (string) $validated['body'],
                    $validated['reply_to_id'],
                ),
            };
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [...MessagePayload::for($message->loadMissing(['sender', 'replyTo.sender'])), 'mine' => true],
        ], 201);
    }

    public function read(Request $request, int $conversation): JsonResponse
    {
        $room = $this->readable($request, $conversation);

        return response()->json(['read' => $this->messenger->markRead($room, $request->user())]);
    }

    protected function readable(Request $request, int $conversation): Conversation
    {
        $room = Conversation::query()->with('participants')->findOrFail($conversation);

        abort_unless($room->canBeReadBy($request->user()), 404);

        return $room;
    }
}
