<?php

namespace App\Services\Chat;

use App\Events\Chat\RoomUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReport;
use App\Models\User;
use App\Notifications\GroupSilenced;
use App\Notifications\MessageReported;
use App\Services\Admin\Auditor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Throwable;

/**
 * Running a batch group: pinning, announcement mode, keeping somebody from
 * posting for a while, and the reports members send.
 *
 * Done by people (owner decision, 2026-11-10): nothing here reads a message
 * to decide anything, and a report takes nothing down by itself. Who may do
 * these things is `Conversation::canBeModeratedBy()`; every one of them is
 * audited.
 */
class Moderation
{
    /** How long somebody can be kept from posting, as offered on screen. */
    public const SILENCE_FOR = [
        '1h' => ['An hour', 60],
        '1d' => ['A day', 60 * 24],
        '7d' => ['A week', 60 * 24 * 7],
    ];

    public function __construct(protected Auditor $auditor) {}

    /** Pin one message, or unpin with null. One pinned message at a time. */
    public function pin(Conversation $room, ?Message $message, User $actor): void
    {
        $this->mustModerate($room, $actor);

        if ($message && ($message->conversation_id !== $room->id || $message->trashed())) {
            throw new InvalidArgumentException('That message is not in this group.');
        }

        $before = $room->pinnedMessage?->preview();
        $room->forceFill(['pinned_message_id' => $message?->id])->save();

        $this->auditor->actingAs($actor)->action($message ? 'chat.pinned' : 'chat.unpinned', $room, [
            'pinned' => ['from' => $before, 'to' => $message?->preview()],
        ], 'Group: '.$room->displayTitle());

        $this->announce($room);
    }

    /** Unpin because the pinned message is gone. The removal itself is what goes on the record. */
    public function clearPin(Conversation $room): void
    {
        $room->forceFill(['pinned_message_id' => null])->save();
        $this->announce($room);
    }

    public function setAnnouncementOnly(Conversation $room, bool $on, User $actor): void
    {
        $this->mustModerate($room, $actor);

        if ($room->announcement_only === $on) {
            return;
        }

        $room->forceFill(['announcement_only' => $on])->save();

        $this->auditor->actingAs($actor)->action('chat.announcement_only', $room, [
            'announcement_only' => ['from' => ! $on, 'to' => $on],
        ], 'Group: '.$room->displayTitle());

        $this->announce($room);
    }

    /** Keep a student from posting until a time. They can still read. */
    public function silence(Conversation $room, User $student, string $for, User $actor, ?string $reason = null): Carbon
    {
        $this->mustModerate($room, $actor);

        $participant = $room->participantFor($student);

        if (! $participant || $participant->left_at || $room->canBeModeratedBy($student)) {
            throw new InvalidArgumentException('Only a member of this group can be kept from posting in it.');
        }

        $minutes = self::SILENCE_FOR[$for][1] ?? throw new InvalidArgumentException('Choose how long.');
        $until = now()->addMinutes($minutes);

        $participant->forceFill(['silenced_until' => $until])->save();

        $this->auditor->actingAs($actor)->action('chat.silenced', $student, [
            'group' => $room->displayTitle(),
            'until' => $until->format('j M Y, g:i a'),
            'reason' => filled($reason) ? trim($reason) : null,
        ], $student->name);

        $student->notify(new GroupSilenced($room, $until, filled($reason) ? trim($reason) : null));

        return $until;
    }

    public function lift(Conversation $room, User $student, User $actor): void
    {
        $this->mustModerate($room, $actor);

        $participant = $room->participantFor($student);

        if (! $participant?->isSilenced()) {
            return;
        }

        $participant->forceFill(['silenced_until' => null])->save();

        $this->auditor->actingAs($actor)->action('chat.silence_lifted', $student, [
            'group' => $room->displayTitle(),
        ], $student->name);
    }

    /**
     * A member reports a message. The people who run the group are told;
     * reporting the same message twice is one report.
     */
    public function report(Message $message, User $reporter, ?string $reason): ?MessageReport
    {
        $room = $message->conversation;

        if (! $room->isGroup() || ! $room->includes($reporter)) {
            throw new InvalidArgumentException('Only a member of the group can report a message in it.');
        }

        if ($message->sender_id === $reporter->id || $message->trashed()) {
            throw new InvalidArgumentException('That message cannot be reported.');
        }

        try {
            $report = MessageReport::query()->create([
                'message_id' => $message->id,
                'conversation_id' => $room->id,
                'reporter_id' => $reporter->id,
                'reason' => filled($reason) ? trim($reason) : null,
            ]);
        } catch (QueryException) {
            return null;
        }

        Notification::send($this->moderatorsToTell($room), new MessageReported($report));

        return $report;
    }

    /** Nothing needed doing. */
    public function dismiss(MessageReport $report, User $actor): void
    {
        $this->mustModerate($report->conversation, $actor);

        MessageReport::query()
            ->where('message_id', $report->message_id)
            ->where('status', 'open')
            ->update(['status' => 'dismissed', 'handled_by' => $actor->id, 'handled_at' => now()]);

        $this->auditor->actingAs($actor)->action('chat.report_dismissed', $report, [
            'group' => $report->conversation->displayTitle(),
        ], 'Report on a message in '.$report->conversation->displayTitle());
    }

    /** Mark every open report on a message as dealt with, after it was removed. */
    public function resolveReportsOn(Message $message, User $actor): void
    {
        MessageReport::query()
            ->where('message_id', $message->id)
            ->where('status', 'open')
            ->update(['status' => 'actioned', 'handled_by' => $actor->id, 'handled_at' => now()]);
    }

    /** @return array{announcementOnly: bool, pinned: array<string, mixed>|null} */
    public function settings(Conversation $room): array
    {
        $pinned = $room->pinned_message_id
            ? Message::query()->with('sender:id,name')->find($room->pinned_message_id)
            : null;

        return [
            'announcementOnly' => (bool) $room->announcement_only,
            'pinned' => $pinned ? [
                'id' => $pinned->id,
                'sender' => $pinned->sender?->name ?? 'Somebody',
                'preview' => str($pinned->preview())->limit(160)->toString(),
                'at' => $pinned->sent_at->format('j M, g:i a'),
            ] : null,
        ];
    }

    /* -------------------------------------------------------------------- */

    protected function mustModerate(Conversation $room, User $actor): void
    {
        abort_unless($room->canBeModeratedBy($actor), 403);
    }

    /** The batch's trainer and the owner: the people a report goes to by name. */
    protected function moderatorsToTell(Conversation $room)
    {
        $trainerId = $room->trainerId();

        return User::query()
            ->where(fn ($query) => $query
                ->where('is_owner', true)
                ->when($trainerId, fn ($inner) => $inner->orWhere('id', $trainerId)))
            ->get();
    }

    protected function announce(Conversation $room): void
    {
        try {
            event(new RoomUpdated($room->id, $this->settings($room->fresh())));
        } catch (Throwable $e) {
            Log::warning('Chat broadcast failed; clients will pick this up by polling.', [
                'event' => RoomUpdated::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
