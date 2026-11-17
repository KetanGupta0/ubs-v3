<?php

namespace App\Services\Client;

use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\User;
use App\Notifications\MeetingNotice;
use App\Notifications\MinutesAnswered;
use App\Notifications\MinutesPublished;
use App\Services\Admin\Auditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Meetings and minutes (CL11).
 *
 * Minutes settle "we never agreed to that": we write them, publish them as
 * a version that never changes, and the client acknowledges them or says
 * what is wrong, in which case the correction is the next version. What the
 * client agreed to do becomes a "waiting on you" item; what we agreed to do
 * is listed with who on our side owns it.
 */
class Meetings
{
    public function __construct(
        protected Auditor $auditor,
        protected WaitingItems $waiting,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function schedule(User $staff, array $data): Meeting
    {
        $meeting = Meeting::query()->create([
            ...$data,
            'status' => 'scheduled',
            'created_by' => $staff->id,
        ]);

        $this->auditor->actingAs($staff)->created($meeting, $meeting->title);

        if ($meeting->starts_at->isFuture()) {
            $this->tellClient($meeting, 'scheduled');
        }

        return $meeting;
    }

    /** @param  array<string, mixed>  $data */
    public function reschedule(Meeting $meeting, User $staff, array $data): void
    {
        if ($meeting->status !== 'scheduled') {
            throw new InvalidArgumentException('Only a meeting that has not happened yet can be changed.');
        }

        $meeting->fill($data);
        $moved = $meeting->isDirty(['starts_at', 'duration_minutes', 'link', 'location']);
        $this->auditor->actingAs($staff)->updated($meeting, label: $meeting->title);
        $meeting->save();

        if ($moved && $meeting->starts_at->isFuture()) {
            $this->tellClient($meeting, 'changed');
        }
    }

    public function cancel(Meeting $meeting, User $staff, ?string $reason): void
    {
        if ($meeting->status !== 'scheduled') {
            throw new InvalidArgumentException('Only a meeting that has not happened yet can be cancelled.');
        }

        $meeting->forceFill(['status' => 'cancelled', 'cancel_reason' => filled($reason) ? trim($reason) : null])->save();
        $this->auditor->actingAs($staff)->action('meeting.cancelled', $meeting, [], $meeting->title);

        if ($meeting->starts_at->isFuture()) {
            $this->tellClient($meeting, 'cancelled');
        }
    }

    /**
     * Write or rewrite the version being drafted.
     *
     * @param  array{attendees: array<int, string>, body: string, client_actions: array<int, array{title: string, due_on: ?string}>, team_actions: array<int, array{text: string, owner: ?string}>}  $data
     */
    public function saveDraft(Meeting $meeting, User $staff, array $data): MeetingMinutes
    {
        if ($meeting->status === 'cancelled') {
            throw new InvalidArgumentException('A cancelled meeting has no minutes.');
        }

        if ($data['client_actions'] !== [] && ! $meeting->project_id) {
            throw new InvalidArgumentException('Link the meeting to a project to give the client actions: they become items waiting on them in that project.');
        }

        $values = [
            'attendees' => array_values($data['attendees']),
            'body' => trim($data['body']),
            'client_actions' => array_values($data['client_actions']) ?: null,
            'team_actions' => array_values($data['team_actions']) ?: null,
        ];

        $draft = $meeting->minutes()->whereNull('published_at')->first();

        if ($draft) {
            $draft->fill($values)->save();

            return $draft;
        }

        return $meeting->minutes()->create($values + [
            'version' => ((int) MeetingMinutes::query()->where('meeting_id', $meeting->id)->max('version')) + 1,
        ]);
    }

    /** Fix the draft as the minutes, raise what the client agreed to do, and tell them. */
    public function publish(Meeting $meeting, User $staff): MeetingMinutes
    {
        $draft = $meeting->minutes()->whereNull('published_at')->first();

        if (! $draft) {
            throw new InvalidArgumentException('Write the minutes first.');
        }

        if ($meeting->starts_at->isFuture()) {
            throw new InvalidArgumentException('Minutes are published after the meeting has started.');
        }

        // reorder(): the relation already sorts oldest first.
        $previous = $meeting->minutes()->whereNotNull('published_at')->reorder('version', 'desc')->first();

        DB::transaction(function () use ($meeting, $draft, $staff) {
            $draft->forceFill(['published_at' => now(), 'published_by' => $staff->id])->save();
            $meeting->forceFill(['status' => 'held'])->save();
        });

        // A correction repeats the actions already raised; only new ones are raised.
        $raised = collect($previous?->client_actions ?? [])->pluck('title')->map(fn ($title) => mb_strtolower(trim($title)));

        foreach ($draft->client_actions ?? [] as $action) {
            if ($raised->contains(mb_strtolower(trim($action['title'])))) {
                continue;
            }

            $item = $this->waiting->raise($meeting->project, $staff, [
                'kind' => 'action',
                'title' => $action['title'],
                'details' => 'Agreed in the meeting on '.$meeting->starts_at->format('j M Y').": {$meeting->title}.",
                'due_on' => $action['due_on'] ?? null,
                'blocks_work' => false,
            ]);
            $item->forceFill(['meeting_id' => $meeting->id])->save();
        }

        $this->auditor->actingAs($staff)->action('meeting.minutes_published', $meeting, ['version' => $draft->version], $meeting->title);
        Notification::send($meeting->client->clientRecipients('all'), new MinutesPublished($draft->fresh('meeting')));

        return $draft;
    }

    public function acknowledge(MeetingMinutes $minutes, User $member, Request $request): void
    {
        $this->answer($minutes, $member, [
            'acknowledged_at' => now(),
            'acknowledged_by' => $member->id,
            'acknowledged_ip' => $request->ip(),
        ], 'meeting.minutes_acknowledged');
    }

    public function dispute(MeetingMinutes $minutes, User $member, string $note): void
    {
        $this->answer($minutes, $member, [
            'disputed_at' => now(),
            'disputed_by' => $member->id,
            'dispute_note' => trim($note),
        ], 'meeting.minutes_disputed');
    }

    /* -------------------------------------------------------------------- */

    /** @param  array<string, mixed>  $changes */
    protected function answer(MeetingMinutes $minutes, User $member, array $changes, string $action): void
    {
        DB::transaction(function () use ($minutes, $changes) {
            $locked = MeetingMinutes::query()->lockForUpdate()->findOrFail($minutes->id);
            $latest = (int) MeetingMinutes::query()->where('meeting_id', $locked->meeting_id)->whereNotNull('published_at')->max('version');

            if (! $locked->awaitsAnswer() || $locked->version !== $latest) {
                throw new InvalidArgumentException('These minutes have already been answered, or a newer version replaces them.');
            }

            $locked->forceFill($changes)->save();
        });

        $minutes->refresh();
        $meeting = $minutes->meeting;

        $this->auditor->actingAs($member)->action($action, $meeting, ['version' => $minutes->version], $meeting->title);

        $staff = User::query()->whereIn('id', array_filter([$meeting->created_by, $minutes->published_by, $meeting->project?->manager_id]))->get();
        Notification::send($staff->isNotEmpty() ? $staff : User::query()->where('is_owner', true)->get(), new MinutesAnswered($minutes));
    }

    protected function tellClient(Meeting $meeting, string $what): void
    {
        Notification::send($meeting->client->clientRecipients('all'), new MeetingNotice($meeting, $what));
    }
}
