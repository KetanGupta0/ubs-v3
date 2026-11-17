<?php

namespace App\Services\Client;

use App\Models\ChangeRequest;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ChangeRequestNotice;
use App\Services\Admin\Auditor;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Change requests (CL12).
 *
 * Asked for by the client (or by us for them), estimated and sent as a
 * quotation addendum frozen with its hash, then approved by signing it or
 * declined. Approval adds the amount to the project's value, moves its
 * target date, and adds the work as a milestone carrying the amount, so it
 * is invoiced when the client accepts it (CL9) like any other milestone.
 */
class ChangeRequests
{
    public function __construct(
        protected Auditor $auditor,
        protected ClientDocuments $documents,
    ) {}

    public function raise(Project $project, User $by, string $title, string $description, ?ChangeRequest $previous = null): ChangeRequest
    {
        $change = ChangeRequest::query()->create([
            'number' => ChangeRequest::nextNumber(),
            'project_id' => $project->id,
            'client_id' => $project->client->clientAccount()->id,
            'previous_id' => $previous?->id,
            'title' => trim($title),
            'description' => trim($description),
            'raised_by' => $by->id,
            'status' => 'requested',
        ]);

        $this->auditor->actingAs($by)->action('change_request.raised', $change, ['project' => $project->id], $change->number);

        if ($by->isClient()) {
            Notification::send($this->staffFor($project), new ChangeRequestNotice($change, 'raised'));
        }

        return $change;
    }

    /** Estimate it and send it as an addendum, fixed from this moment. */
    public function quote(ChangeRequest $change, User $staff, int $amount, int $days, ?string $note): void
    {
        if ($change->status !== 'requested') {
            throw new InvalidArgumentException('Only a request still being estimated can be quoted.');
        }

        $change->forceFill([
            'amount' => $amount,
            'days' => $days,
            'estimate_note' => filled($note) ? trim($note) : null,
            'quoted_at' => now(),
            'quoted_by' => $staff->id,
        ]);
        $this->documents->freezeChangeRequest($change);
        $change->forceFill(['status' => 'quoted'])->save();

        $this->auditor->actingAs($staff)->action('change_request.quoted', $change, [
            'amount' => $amount,
            'days' => $days,
            'sha256' => $change->pdf_sha256,
        ], $change->number);

        Notification::send($change->client->clientRecipients('signing'), new ChangeRequestNotice($change, 'quoted'));
    }

    public function decline(ChangeRequest $change, User $member, string $reason): void
    {
        DB::transaction(function () use ($change, $member, $reason) {
            $locked = ChangeRequest::query()->lockForUpdate()->findOrFail($change->id);

            if (! $locked->canBeAnswered()) {
                throw new InvalidArgumentException('This change request is not waiting for an answer.');
            }

            $locked->forceFill(['status' => 'declined', 'decided_at' => now(), 'decided_by' => $member->id, 'decline_reason' => trim($reason)])->save();
        });

        $change->refresh();
        $this->auditor->actingAs($member)->action('change_request.declined', $change, [], $change->number);
        Notification::send($this->staffFor($change->project, $change->quoted_by), new ChangeRequestNotice($change, 'declined'));
    }

    public function withdraw(ChangeRequest $change, User $staff): void
    {
        if (! in_array($change->status, ['requested', 'quoted'], true)) {
            throw new InvalidArgumentException('Only an open change request can be withdrawn.');
        }

        $change->forceFill(['status' => 'withdrawn', 'decided_at' => now(), 'decided_by' => $staff->id])->save();
        $this->auditor->actingAs($staff)->action('change_request.withdrawn', $change, [], $change->number);
    }

    /** A different estimate is a new request, linked to the one it follows. */
    public function quoteAgain(ChangeRequest $change, User $staff): ChangeRequest
    {
        if (! in_array($change->status, ['declined', 'withdrawn'], true)) {
            throw new InvalidArgumentException('Only a declined or withdrawn change request can be quoted again.');
        }

        return $this->raise($change->project, $staff, $change->title, $change->description, $change);
    }

    /**
     * Called by Signatures inside the signing transaction, once the client's
     * signature over the addendum is recorded.
     */
    public function applyApproval(ChangeRequest $change, User $signer): void
    {
        $project = Project::query()->lockForUpdate()->findOrFail($change->project_id);
        $before = ['budget' => $project->budget, 'target_date' => $project->target_date?->toDateString()];

        $milestone = $project->milestones()->create([
            'title' => "Change {$change->number}: {$change->title}",
            'description' => $change->estimate_note ?? $change->description,
            'due_date' => $change->days ? today()->addDays($change->days) : null,
            'payment_amount' => $change->amount ?: null,
            'progress_percent' => 0,
            'sort_order' => ((int) $project->milestones()->reorder()->max('sort_order')) + 1,
        ]);

        $project->forceFill([
            'budget' => ($project->budget ?? 0) + (int) $change->amount,
            'target_date' => $project->target_date && $change->days ? $project->target_date->copy()->addDays($change->days) : $project->target_date,
        ])->save();

        $change->forceFill([
            'status' => 'approved',
            'decided_at' => now(),
            'decided_by' => $signer->id,
            'milestone_id' => $milestone->id,
        ])->save();

        $project->updates()->create([
            'author_id' => null,
            'title' => "Change {$change->number} approved",
            'body' => "{$change->title}. It adds ".Money::display((int) $change->amount).' before tax'
                .($change->days ? " and {$change->days} days" : '')
                .($project->target_date ? '; the target date is now '.$project->target_date->format('j M Y') : '')
                .'. The work is a new milestone, invoiced when you accept it.',
            'visible_to_client' => true,
        ]);

        $this->auditor->actingAs($signer)->action('project.changed_by_request', $project, [
            'change_request' => $change->id,
            'before' => $before,
            'after' => ['budget' => $project->budget, 'target_date' => $project->target_date?->toDateString()],
        ], $project->name);
    }

    /** @return Collection<int, User> */
    protected function staffFor(Project $project, ?int $also = null): Collection
    {
        $staff = User::query()->whereIn('id', array_filter([$project->manager_id, $also]))->get()->filter->canSignIn();

        return $staff->isNotEmpty() ? $staff : User::query()->where('is_owner', true)->get();
    }
}
