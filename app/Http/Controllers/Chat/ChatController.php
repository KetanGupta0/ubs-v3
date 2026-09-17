<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageReceipt;
use App\Services\Chat\Messenger;
use App\Services\Chat\Rooms;
use App\Support\Chat\MessagePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Chat, for all three panels.
 *
 * One controller rather than one per role, because which rooms a person can
 * see is a question about them and not about the URL they arrived on. Three
 * copies of this would answer that question three ways within a quarter, and
 * the one that got it wrong would be the one leaking a client's thread.
 */
class ChatController extends Controller
{
    /** How many messages a thread opens with. Older ones load on scroll. */
    public const PAGE = 50;

    public function __construct(
        protected Rooms $rooms,
        protected Messenger $messenger,
    ) {}

    public function index(Request $request): Response
    {
        $this->ensureRooms($request);

        return Inertia::render('chat/Index', [
            'conversations' => $this->conversationList($request),
            'conversation' => null,
            'messages' => [],
            'participants' => [],
        ]);
    }

    public function show(Request $request, int $conversation): Response
    {
        $this->ensureRooms($request);

        $room = $this->readable($request, $conversation);
        $user = $request->user();

        // Opening a thread is what "delivered" means for anything that arrived
        // while this person was away.
        $this->messenger->markDelivered($room, $user);
        $this->messenger->markRead($room, $user);

        return Inertia::render('chat/Index', [
            'conversations' => $this->conversationList($request),
            'conversation' => $this->conversationArray($room, $user),
            'messages' => $this->messagesFor($room, $user),
            'participants' => $this->participantsFor($room),
        ]);
    }

    /**
     * What the poll asks for.
     *
     * The fallback for a browser that cannot hold a websocket open — a hotel
     * network, a corporate proxy, a phone on a bad train. It answers the same
     * shape the socket broadcasts, so the client has one way to add a message
     * to the screen rather than two.
     */
    public function messages(Request $request, int $conversation): JsonResponse
    {
        $room = $this->readable($request, $conversation);
        $user = $request->user();

        $after = (int) $request->integer('after');
        $before = (int) $request->integer('before');

        $query = $room->messages()
            ->withTrashed()
            ->with(['sender:id,name,avatar_path,role', 'replyTo.sender:id,name']);

        if ($before > 0) {
            // Scrolling back through history.
            $older = (clone $query)->where('id', '<', $before)
                ->orderByDesc('id')
                ->take(self::PAGE)
                ->get()
                ->sortBy('id')
                ->values();

            return response()->json([
                'messages' => $this->decorate($older, $room, $user),
                'hasMore' => $older->isNotEmpty()
                    && $room->messages()->withTrashed()->where('id', '<', $older->first()->id)->exists(),
            ]);
        }

        $fresh = $query->where('id', '>', $after)->inOrder()->take(200)->get();

        if ($fresh->isNotEmpty()) {
            $this->messenger->markDelivered($room, $user);
        }

        return response()->json([
            'messages' => $this->decorate($fresh, $room, $user),
            // The list in the sidebar goes stale too, so it rides along.
            'conversations' => $this->conversationList($request),
        ]);
    }

    /**
     * Send one.
     *
     * Answers with the message itself rather than a redirect, because the
     * screen adds it to the thread the moment it lands instead of waiting for
     * the socket to bring back news of something it just did. The socket will
     * deliver the same message a moment later and the thread deduplicates on
     * the id, so a slow broadcast shows as nothing at all.
     */
    public function send(Request $request, int $conversation): RedirectResponse|JsonResponse
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
        ], [
            'file.max' => 'That file is too large to send here.',
        ]);

        try {
            $message = match ($validated['kind']) {
                'image' => $this->messenger->sendImage(
                    $room,
                    $request->user(),
                    $this->file($request),
                    $validated['body'],
                    $validated['reply_to_id'],
                ),
                'audio' => $this->messenger->sendAudio(
                    $room,
                    $request->user(),
                    $this->file($request),
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
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['body' => $e->getMessage()]);
        }

        if (! $request->expectsJson()) {
            return back();
        }

        $message->loadMissing(['sender', 'replyTo.sender']);

        return response()->json([
            'message' => [...MessagePayload::for($message), 'mine' => true],
        ]);
    }

    public function read(Request $request, int $conversation): JsonResponse
    {
        $room = $this->readable($request, $conversation);

        return response()->json([
            'read' => $this->messenger->markRead($room, $request->user()),
        ]);
    }

    public function destroy(Request $request, int $conversation, int $message): RedirectResponse
    {
        $room = $this->readable($request, $conversation);

        $record = $room->messages()->findOrFail($message);

        $this->messenger->remove($record, $request->user());

        return back();
    }

    /** Every photo and voice note in one room, newest first. */
    public function gallery(Request $request, int $conversation): JsonResponse
    {
        $room = $this->readable($request, $conversation);

        $media = $room->messages()
            ->whereNot('kind', 'text')
            ->whereNotNull('media_path')
            ->with('sender:id,name')
            ->latest('sent_at')
            ->take(120)
            ->get();

        return response()->json([
            'media' => $media->map(fn (Message $message) => MessagePayload::for($message)),
        ]);
    }

    /**
     * Serve a photo or a voice note.
     *
     * The link is signed and expires, and this still checks who is asking:
     * a signature proves the link was ours, not that the person holding it
     * belongs in the room.
     */
    public function media(Request $request, int $message): StreamedResponse
    {
        $record = Message::query()->findOrFail($message);

        abort_unless($record->conversation->canBeReadBy($request->user()), 404);
        abort_unless($record->media_path && Storage::disk('private')->exists($record->media_path), 404);

        return Storage::disk('private')->response(
            $record->media_path,
            null,
            ['Content-Type' => $record->media_mime, 'Cache-Control' => 'private, max-age=600'],
        );
    }

    /* ------------------------------------------------------------ helpers */

    /** A room this person may open, or a 404 that says nothing about it. */
    protected function readable(Request $request, int $conversation): Conversation
    {
        $room = Conversation::query()
            ->with(['participants', 'client:id,name', 'project:id,name', 'batch:id,name,course_id'])
            ->findOrFail($conversation);

        abort_unless($room->canBeReadBy($request->user()), 404);

        return $room;
    }

    /**
     * Make sure this person's rooms exist before they look for them.
     *
     * Done on opening the screen rather than when a project or a batch is
     * created, so accounts that predate this phase are not left without a
     * thread until something else happens to them.
     */
    protected function ensureRooms(Request $request): void
    {
        $user = $request->user();

        if ($user->isClient()) {
            $this->rooms->ensureClientRooms($user);
        }

        if ($user->isStudent()) {
            $this->rooms->ensureStudentRooms($user);
        }
    }

    /** @return array<int, array<string, mixed>> */
    protected function conversationList(Request $request): array
    {
        $user = $request->user();
        $rooms = $this->rooms->visibleTo($user);

        $participants = ConversationParticipant::query()
            ->where('user_id', $user->id)
            ->whereIn('conversation_id', $rooms->pluck('id'))
            ->get()
            ->keyBy('conversation_id');

        $lastMessages = $this->lastMessages($rooms->pluck('id'));

        return $rooms->map(function (Conversation $room) use ($user, $participants, $lastMessages) {
            $participant = $participants[$room->id] ?? null;
            $last = $lastMessages[$room->id] ?? null;

            return [
                'id' => $room->id,
                'type' => $room->type,
                'title' => $room->displayTitle(),
                'subtitle' => $this->subtitleFor($room, $user),
                'preview' => $room->last_message_preview,
                'at' => $room->last_message_at?->diffForHumans(short: true),

                // A member counts what arrived since they last looked. Staff
                // are not members of every room, so a count would be either
                // zero or the whole thread; what they actually need to know is
                // which rooms are waiting on an answer.
                'unread' => $participant ? $participant->unreadCount() : 0,
                'awaitingReply' => $last !== null && ! ($last->sender?->isAdmin() ?? false),

                'muted' => (bool) $participant?->is_muted,
                'avatarUrl' => $room->client?->avatar_url,
            ];
        })->values()->all();
    }

    /**
     * The newest message in each of these rooms, in one query.
     *
     * @param  Collection<int, int>  $conversationIds
     * @return Collection<int, Message>
     */
    protected function lastMessages($conversationIds)
    {
        if ($conversationIds->isEmpty()) {
            return collect();
        }

        $ids = DB::table('messages')
            ->selectRaw('max(id) as id')
            ->whereIn('conversation_id', $conversationIds)
            ->whereNull('deleted_at')
            ->groupBy('conversation_id')
            ->pluck('id');

        return Message::query()
            ->whereIn('id', $ids)
            ->with('sender:id,role')
            ->get()
            ->keyBy('conversation_id');
    }

    /** @return array<string, mixed> */
    protected function conversationArray(Conversation $room, $user): array
    {
        return [
            'id' => $room->id,
            'type' => $room->type,
            'title' => $room->displayTitle(),
            'subtitle' => $this->subtitleFor($room, $user),
            'isGroup' => $room->isGroup(),
            'canRemoveAny' => $user->isAdmin() && $user->hasPermission('chat.reply'),
        ];
    }

    /**
     * The line under the room's name.
     *
     * Deliberately different per role: a client wants to know which project a
     * thread is about, and an administrator wants to know whose thread it is.
     */
    protected function subtitleFor(Conversation $room, $user): ?string
    {
        if ($room->isGroup()) {
            return $room->batch?->course?->title ?? 'Batch group';
        }

        if ($user->isClient()) {
            return $room->project?->name ?? 'General';
        }

        return collect([$room->client?->name, $room->project?->name])->filter()->join(' · ');
    }

    /**
     * Who is in the room.
     *
     * A name and a face, and nothing else. An email address is not needed to
     * draw a row in a participant list, and a batch group is forty students
     * who did not agree to share theirs with each other.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function participantsFor(Conversation $room): array
    {
        return $room->participants()
            ->whereNull('left_at')
            ->with('user:id,name,avatar_path,role')
            ->get()
            ->map(fn (ConversationParticipant $participant) => [
                'id' => $participant->user_id,
                'name' => $participant->user?->name ?? 'Somebody',
                'avatarUrl' => $participant->user?->avatar_url,
                'role' => $participant->role,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function messagesFor(Conversation $room, $user): array
    {
        $messages = $room->messages()
            ->withTrashed()
            ->with(['sender:id,name,avatar_path,role', 'replyTo.sender:id,name'])
            ->orderByDesc('id')
            ->take(self::PAGE)
            ->get()
            ->sortBy('id')
            ->values();

        return $this->decorate($messages, $room, $user);
    }

    /**
     * Add the ticks.
     *
     * Only to the viewer's own messages: whether somebody else's message has
     * been read by a third person is not the viewer's business, and in a batch
     * group it would be a list of forty names.
     *
     * @param  Collection<int, Message>  $messages
     * @return array<int, array<string, mixed>>
     */
    protected function decorate($messages, Conversation $room, $user): array
    {
        $mine = $messages->where('sender_id', $user->id)->pluck('id');

        $receipts = $mine->isEmpty() ? collect() : MessageReceipt::query()
            ->whereIn('message_id', $mine)
            ->get()
            ->groupBy('message_id');

        return $messages->map(function (Message $message) use ($user, $receipts) {
            $payload = MessagePayload::for($message);

            if ($message->sender_id === $user->id) {
                $rows = $receipts[$message->id] ?? collect();

                $payload['deliveredTo'] = $rows->whereNotNull('delivered_at')->count();
                $payload['readBy'] = $rows->whereNotNull('read_at')->count();
                $payload['recipients'] = $rows->count();
            }

            $payload['mine'] = $message->sender_id === $user->id;

            return $payload;
        })->values()->all();
    }

    protected function file(Request $request)
    {
        $file = $request->file('file');

        abort_unless($file !== null, 422, 'Nothing was attached.');

        return $file;
    }
}
