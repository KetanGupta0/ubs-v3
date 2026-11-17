<?php

namespace App\Services\Client;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Models\WaitingItem;
use App\Notifications\WaitingItemAnswered;
use App\Notifications\WaitingOnYou;
use App\Services\Admin\Auditor;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * "Waiting on you" items (CL10).
 *
 * We ask a client for what only they can give: assets, content, access, a
 * decision. They reply with words, files or both; files join the
 * organisation's documents, marked as sent by them. We close the item when
 * we have what we needed, or ask again saying what is missing. Every step
 * is an event, and the time the project waited on the client is worked out
 * from those events, so it is the same number on both sides.
 */
class WaitingItems
{
    public function __construct(protected Auditor $auditor) {}

    /** @param  array{kind: string, title: string, details: ?string, due_on: ?string, blocks_work: bool}  $data */
    public function raise(Project $project, User $staff, array $data): WaitingItem
    {
        $item = DB::transaction(function () use ($project, $staff, $data) {
            $item = $project->waitingItems()->create([
                'client_id' => $project->client->clientAccount()->id,
                'kind' => $data['kind'],
                'title' => trim($data['title']),
                'details' => filled($data['details'] ?? null) ? trim($data['details']) : null,
                'due_on' => $data['due_on'] ?? null,
                'blocks_work' => (bool) $data['blocks_work'],
                'status' => 'open',
                'raised_by' => $staff->id,
            ]);

            $item->events()->create(['user_id' => $staff->id, 'action' => 'raised']);

            return $item;
        });

        $this->auditor->actingAs($staff)->action('waiting.raised', $item, ['project' => $project->id], $item->title);
        $this->tellClient($item, reopened: false);

        return $item;
    }

    /**
     * The client's reply. Anybody in the organisation may give it.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function answer(WaitingItem $item, User $member, ?string $body, array $files): void
    {
        if (! $item->isOpen()) {
            throw new InvalidArgumentException('This is not waiting on you any more.');
        }

        if (blank($body) && $files === []) {
            throw new InvalidArgumentException('Write a reply or attach a file.');
        }

        // Stored first: a file on disk with no row is tidied up later; a row
        // pointing at no file is a broken download somebody will click.
        $documents = collect($files)->map(fn (UploadedFile $file) => Document::query()->create([
            'client_id' => $item->client_id,
            'project_id' => $item->project_id,
            'name' => str(basename($file->getClientOriginalName()))->limit(150, '')->toString() ?: 'File',
            'description' => "Sent in reply to: {$item->title}",
            'path' => $file->store("clients/{$item->client_id}", 'private'),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'category' => 'From the client',
            'visible_to_client' => true,
            'uploaded_by' => $member->id,
        ]));

        DB::transaction(function () use ($item, $member, $body, $documents) {
            $locked = WaitingItem::query()->lockForUpdate()->findOrFail($item->id);

            if (! $locked->isOpen()) {
                throw new InvalidArgumentException('Somebody in your organisation has already replied to this.');
            }

            $locked->events()->create([
                'user_id' => $member->id,
                'action' => 'answered',
                'body' => filled($body) ? trim($body) : null,
                'document_ids' => $documents->pluck('id')->all() ?: null,
            ]);

            $locked->forceFill(['status' => 'answered', 'answered_at' => now(), 'answered_by' => $member->id])->save();
        });

        $item->refresh();

        $this->auditor->actingAs($member)->action('waiting.answered', $item, ['files' => $documents->count()], $item->title);
        Notification::send($this->staffFor($item), new WaitingItemAnswered($item));
    }

    /** Not quite: ask again, saying what is still missing. */
    public function reopen(WaitingItem $item, User $staff, string $note): void
    {
        if ($item->status !== 'answered') {
            throw new InvalidArgumentException('Only an answered item can be asked for again.');
        }

        $this->step($item, $staff, 'reopened', ['status' => 'open', 'answered_at' => null, 'answered_by' => null], trim($note));
        $this->tellClient($item->fresh(), reopened: true);
    }

    /** We have what we needed. */
    public function close(WaitingItem $item, User $staff): void
    {
        if (! in_array($item->status, ['open', 'answered'], true)) {
            throw new InvalidArgumentException('This item is already finished.');
        }

        $this->step($item, $staff, 'closed', ['status' => 'closed', 'closed_at' => now()]);
    }

    public function cancel(WaitingItem $item, User $staff): void
    {
        if (! in_array($item->status, ['open', 'answered'], true)) {
            throw new InvalidArgumentException('This item is already finished.');
        }

        $this->step($item, $staff, 'cancelled', ['status' => 'cancelled', 'closed_at' => now()]);
    }

    /**
     * How long work on the project waited on the client: the union of every
     * period a blocking item was open, so two things asked for at once count
     * once. An item still open counts up to now.
     *
     * @return array{seconds: int, label: string, items: array<int, int>}
     */
    public function waitingTime(Project $project): array
    {
        $now = now()->getTimestamp();
        $blocking = [];
        $perItem = [];

        foreach ($project->waitingItems()->with('events')->get() as $item) {
            $periods = $this->openPeriods($item, $now);
            $perItem[$item->id] = array_sum(array_map(fn (array $period) => $period[1] - $period[0], $periods));

            // An item that does not hold work up is shown with its own time,
            // never counted as the project waiting.
            if ($item->blocks_work) {
                array_push($blocking, ...$periods);
            }
        }

        $seconds = $this->union($blocking);

        return ['seconds' => $seconds, 'label' => self::duration($seconds), 'items' => $perItem];
    }

    /** Coarse on purpose: this is a conversation aid, not a stopwatch. */
    public static function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'no time';
        }

        if ($seconds < 86400) {
            return 'less than a day';
        }

        $days = (int) round($seconds / 86400);

        return $days === 1 ? '1 day' : "{$days} days";
    }

    /**
     * Items as both sides' screens show them, with each step and the files
     * sent with it.
     *
     * @param  EloquentCollection<int, WaitingItem>  $items
     * @param  array<int, int>  $waited  Seconds each item waited, from waitingTime().
     * @return array<int, array<string, mixed>>
     */
    public function forScreen(EloquentCollection $items, array $waited = []): array
    {
        $items->loadMissing('events.user:id,name', 'raiser:id,name');

        $documents = Document::query()
            ->whereIn('id', $items->flatMap(fn (WaitingItem $item) => $item->events->flatMap(fn ($event) => $event->document_ids ?? []))->unique()->all())
            ->get(['id', 'name', 'size', 'mime'])
            ->keyBy('id');

        return $items->map(fn (WaitingItem $item) => [
            'id' => $item->id,
            'kind' => $item->kind,
            'kindLabel' => $item->kindLabel(),
            'title' => $item->title,
            'details' => $item->details,
            'dueOn' => $item->due_on?->format('j M Y'),
            'overdue' => $item->isOverdue(),
            'blocksWork' => $item->blocks_work,
            'status' => $item->status,
            'statusLabel' => $item->statusLabel(),
            'askedAt' => $item->created_at->format('j M Y'),
            'askedBy' => $item->raiser?->name,
            'waited' => isset($waited[$item->id]) ? self::duration($waited[$item->id]) : null,
            'events' => $item->events->map(fn ($event) => [
                'action' => $event->action,
                'by' => $event->user?->name,
                'at' => $event->created_at->format('j M Y, g:i a'),
                'body' => $event->body,
                'files' => collect($event->document_ids ?? [])
                    ->map(fn ($id) => $documents->get($id))
                    ->filter()
                    ->map(fn (Document $document) => ['id' => $document->id, 'name' => $document->name, 'size' => $document->sizeLabel()])
                    ->values(),
            ])->values(),
        ])->values()->all();
    }

    /* -------------------------------------------------------------------- */

    protected function step(WaitingItem $item, User $staff, string $action, array $changes, ?string $body = null): void
    {
        DB::transaction(function () use ($item, $staff, $action, $changes, $body) {
            $item->events()->create(['user_id' => $staff->id, 'action' => $action, 'body' => $body]);
            $item->forceFill($changes)->save();
        });

        $this->auditor->actingAs($staff)->action("waiting.{$action}", $item, [], $item->title);
    }

    /**
     * The stretches an item spent waiting on the client: from being asked
     * (or asked again) to being answered, closed or cancelled.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    protected function openPeriods(WaitingItem $item, int $now): array
    {
        $periods = [];
        $start = null;

        foreach ($item->events as $event) {
            $at = $event->created_at->getTimestamp();

            if (in_array($event->action, ['raised', 'reopened'], true)) {
                $start ??= $at;
            } elseif ($start !== null) {
                $periods[] = [$start, $at];
                $start = null;
            }
        }

        if ($start !== null) {
            $periods[] = [$start, $now];
        }

        return $periods;
    }

    /** @param  array<int, array{0: int, 1: int}>  $periods  Total seconds covered, overlaps counted once. */
    protected function union(array $periods): int
    {
        usort($periods, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $total = 0;
        $current = null;

        foreach ($periods as [$from, $to]) {
            if ($current !== null && $from <= $current[1]) {
                $current[1] = max($current[1], $to);

                continue;
            }

            if ($current !== null) {
                $total += $current[1] - $current[0];
            }

            $current = [$from, $to];
        }

        return $current === null ? $total : $total + $current[1] - $current[0];
    }

    protected function tellClient(WaitingItem $item, bool $reopened): void
    {
        Notification::send($item->client->clientRecipients($item->audience()), new WaitingOnYou($item, $reopened));
    }

    /** @return Collection<int, User> Whoever asked, the project's manager, or else the owner. */
    protected function staffFor(WaitingItem $item): Collection
    {
        $ids = array_filter([$item->raised_by, $item->project->manager_id]);
        $staff = User::query()->whereIn('id', $ids)->get()->filter->canSignIn();

        return $staff->isNotEmpty() ? $staff : User::query()->where('is_owner', true)->get();
    }
}
