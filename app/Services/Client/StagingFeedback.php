<?php

namespace App\Services\Client;

use App\Models\FeedbackItem;
use App\Models\Project;
use App\Models\User;
use App\Notifications\FeedbackNotice;
use App\Services\Admin\Auditor;
use App\Support\ImageUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Feedback on the staging preview (CL13).
 *
 * The client's technical people or an owner report a bug or something to
 * change, with screenshots (re-encoded, which drops whatever metadata came
 * with them) and the milestone it holds up. We move it along; when we say
 * it is fixed, the client confirms it or says it is still happening. Every
 * step is an event both sides read.
 */
class StagingFeedback
{
    /** Which way each side may move an item. */
    public const STAFF_MOVES = [
        'acknowledged' => ['new'],
        'in_progress' => ['new', 'acknowledged'],
        'fixed' => ['new', 'acknowledged', 'in_progress'],
        'declined' => ['new', 'acknowledged', 'in_progress'],
    ];

    public function __construct(protected Auditor $auditor) {}

    /**
     * @param  array{kind: string, title: string, details: string, page_url: ?string, milestone_id: ?int}  $data
     * @param  array<int, UploadedFile>  $screenshots
     */
    public function report(Project $project, User $member, array $data, array $screenshots): FeedbackItem
    {
        $paths = collect($screenshots)->map(fn (UploadedFile $file, int $index) => ImageUpload::store(
            $file, 'private', "feedback/{$project->client_id}", 2000, 'jpg', "screenshots.{$index}",
        ))->all();

        $item = DB::transaction(function () use ($project, $member, $data, $paths) {
            $item = FeedbackItem::query()->create([
                'project_id' => $project->id,
                'client_id' => $project->client->clientAccount()->id,
                'milestone_id' => $data['milestone_id'] ?? null,
                'reported_by' => $member->id,
                'kind' => $data['kind'],
                'title' => trim($data['title']),
                'details' => trim($data['details']),
                'page_url' => $data['page_url'] ?? null,
                'screenshots' => $paths ?: null,
                'status' => 'new',
            ]);

            $item->events()->create(['user_id' => $member->id, 'status' => 'new']);

            return $item;
        });

        $this->auditor->actingAs($member)->action('feedback.reported', $item, ['project' => $project->id], $item->title);
        Notification::send($this->staffFor($project), new FeedbackNotice($item, 'new'));

        return $item;
    }

    /** Our side moves it on: seen, being worked on, fixed, or not changing. */
    public function move(FeedbackItem $item, User $staff, string $to, ?string $note): void
    {
        if (! in_array($item->status, self::STAFF_MOVES[$to] ?? [], true)) {
            throw new InvalidArgumentException('It cannot move there from '.mb_strtolower(FeedbackItem::statusLabel($item->status)).'.');
        }

        if ($to === 'declined' && blank($note)) {
            throw new InvalidArgumentException('Say why it is not changing.');
        }

        $this->step($item, $staff, $to, $note, ['fixed_at' => $to === 'fixed' ? now() : $item->fixed_at, 'closed_at' => $to === 'declined' ? now() : null]);

        if (in_array($to, ['fixed', 'declined'], true) || filled($note)) {
            $this->tellReporter($item, $to);
        }
    }

    /** The client checks the fix: it works, or it is still happening. */
    public function confirm(FeedbackItem $item, User $member, bool $works, ?string $note): void
    {
        if ($item->status !== 'fixed') {
            throw new InvalidArgumentException('Only something we have marked fixed can be checked.');
        }

        if (! $works && blank($note)) {
            throw new InvalidArgumentException('Say what still happens, so we can find it.');
        }

        $this->step($item, $member, $works ? 'closed' : 'in_progress', $note, ['closed_at' => $works ? now() : null]);

        if (! $works) {
            Notification::send($this->staffFor($item->project), new FeedbackNotice($item->fresh(), 'reopened'));
        }
    }

    /** A comment from either side, with no change of status. */
    public function comment(FeedbackItem $item, User $author, string $body): void
    {
        if (! in_array($item->status, FeedbackItem::OPEN, true)) {
            throw new InvalidArgumentException('This is finished. Report it again if it comes back.');
        }

        $item->events()->create(['user_id' => $author->id, 'body' => trim($body)]);
        $item->touch();

        $author->isClient()
            ? Notification::send($this->staffFor($item->project), new FeedbackNotice($item, 'comment'))
            : $this->tellReporter($item, 'comment');
    }

    /* -------------------------------------------------------------------- */

    /** @param  array<string, mixed>  $changes */
    protected function step(FeedbackItem $item, User $by, string $to, ?string $note, array $changes): void
    {
        DB::transaction(function () use ($item, $by, $to, $note, $changes) {
            $item->events()->create(['user_id' => $by->id, 'status' => $to, 'body' => filled($note) ? trim($note) : null]);
            $item->forceFill(['status' => $to] + $changes)->save();
        });

        $this->auditor->actingAs($by)->action("feedback.{$to}", $item, [], $item->title);
    }

    protected function tellReporter(FeedbackItem $item, string $what): void
    {
        if ($item->reporter?->canSignIn()) {
            $item->reporter->notify(new FeedbackNotice($item->fresh(), $what));
        }
    }

    /** @return Collection<int, User> */
    protected function staffFor(Project $project): Collection
    {
        $manager = $project->manager_id ? User::query()->whereKey($project->manager_id)->get()->filter->canSignIn() : collect();

        return $manager->isNotEmpty() ? $manager : User::query()->where('is_owner', true)->get();
    }
}
