<?php

namespace App\Services\Client;

use App\Models\MilestoneSignoff;
use App\Models\PaymentRequest;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\User;
use App\Notifications\MilestoneAnswered;
use App\Notifications\MilestoneSignoffRequested;
use App\Notifications\PaymentRequested;
use App\Services\Admin\Auditor;
use App\Services\Billing\Invoicer;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Milestone acceptance (CL9).
 *
 * We ask the client to accept a milestone, listing what was delivered. An
 * authorised signatory or an owner accepts it, or asks for changes with a
 * comment. Accepting raises the milestone's invoice, once, through the same
 * path every invoice takes, so billing follows the recorded answer rather
 * than a reminder somebody has to remember to send.
 */
class MilestoneSignoffs
{
    public function __construct(
        protected Invoicer $invoicer,
        protected Auditor $auditor,
    ) {}

    /** @param  array<int, string>  $deliverables */
    public function request(ProjectMilestone $milestone, User $staff, array $deliverables, ?string $note): MilestoneSignoff
    {
        if ($milestone->isAccepted()) {
            throw new InvalidArgumentException('The client has already accepted this milestone.');
        }

        if ($milestone->signoffs()->pending()->exists()) {
            throw new InvalidArgumentException('This milestone is already waiting for the client. Withdraw that request first.');
        }

        $signoff = $milestone->signoffs()->create([
            'project_id' => $milestone->project_id,
            'requested_by' => $staff->id,
            'deliverables' => array_values(array_filter(array_map('trim', $deliverables), 'strlen')),
            'note' => filled($note) ? trim($note) : null,
        ]);

        $this->auditor->actingAs($staff)->action('milestone.signoff_requested', $milestone, [
            'signoff' => $signoff->id,
        ], $milestone->title);

        // Those who may accept: signatories and owners.
        Notification::send($milestone->project->client->clientRecipients('signing'), new MilestoneSignoffRequested($signoff));

        return $signoff;
    }

    public function withdraw(MilestoneSignoff $signoff, User $staff): void
    {
        if (! $signoff->isPending()) {
            throw new InvalidArgumentException('Only a request still waiting for an answer can be withdrawn.');
        }

        $signoff->forceFill(['withdrawn_at' => now()])->save();

        $this->auditor->actingAs($staff)->action('milestone.signoff_withdrawn', $signoff->milestone, [
            'signoff' => $signoff->id,
        ], $signoff->milestone->title);
    }

    /** Accept, and raise the milestone's invoice if money is tied to it. */
    public function accept(MilestoneSignoff $signoff, User $client, ?string $comments, Request $request): ?PaymentRequest
    {
        [$signoff, $payment] = DB::transaction(function () use ($signoff, $client, $comments, $request) {
            $signoff = $this->claim($signoff);

            $signoff->forceFill([
                'decision' => 'accepted',
                'decided_by' => $client->id,
                'decided_at' => now(),
                'decided_ip' => $request->ip(),
                'comments' => filled($comments) ? trim($comments) : null,
            ])->save();

            $milestone = $signoff->milestone;
            $milestone->forceFill([
                'accepted_at' => now(),
                'completed_at' => $milestone->completed_at ?? now(),
                'progress_percent' => 100,
            ])->save();

            $payment = $this->raiseInvoice($signoff);

            return [$signoff, $payment];
        });

        $milestone = $signoff->milestone;

        $this->auditor->actingAs($client)->action('milestone.accepted', $milestone, [
            'signoff' => $signoff->id,
            'payment_request' => $payment?->id,
        ], $milestone->title);

        if ($payment) {
            $this->auditor->actingAs($client)->action('payment.requested', $payment, [
                'total' => $payment->total,
                'user_id' => $payment->user_id,
                'by' => 'milestone acceptance',
            ], $payment->reference);

            Notification::send($payment->user->clientRecipients('billing'), new PaymentRequested($payment, $payment->invoice->number));
        }

        Notification::send($this->staffFor($milestone->project, $signoff->requested_by), new MilestoneAnswered($signoff));

        return $payment;
    }

    public function reject(MilestoneSignoff $signoff, User $client, string $comments, Request $request): void
    {
        $signoff = DB::transaction(function () use ($signoff, $client, $comments, $request) {
            $signoff = $this->claim($signoff);

            $signoff->forceFill([
                'decision' => 'rejected',
                'decided_by' => $client->id,
                'decided_at' => now(),
                'decided_ip' => $request->ip(),
                'comments' => trim($comments),
            ])->save();

            return $signoff;
        });

        $this->auditor->actingAs($client)->action('milestone.changes_requested', $signoff->milestone, [
            'signoff' => $signoff->id,
        ], $signoff->milestone->title);

        Notification::send($this->staffFor($signoff->milestone->project, $signoff->requested_by), new MilestoneAnswered($signoff));
    }

    /* -------------------------------------------------------------------- */

    /** Lock the row and make sure it is still waiting: two people answering at once get one answer. */
    protected function claim(MilestoneSignoff $signoff): MilestoneSignoff
    {
        $locked = MilestoneSignoff::query()->lockForUpdate()->findOrFail($signoff->id);

        if (! $locked->isPending()) {
            throw new InvalidArgumentException($locked->withdrawn_at
                ? 'We withdrew this request. There is nothing to answer.'
                : 'Somebody in your organisation has already answered this.');
        }

        return $locked;
    }

    /** One invoice per milestone, however it came to be raised. */
    protected function raiseInvoice(MilestoneSignoff $signoff): ?PaymentRequest
    {
        $milestone = $signoff->milestone;

        if (! $milestone->payment_amount) {
            return null;
        }

        if ($milestone->paymentRequests()->where('status', '!=', 'cancelled')->exists()) {
            return null;
        }

        $project = $milestone->project;

        $payment = PaymentRequest::query()->create([
            'user_id' => $project->client->clientAccount()->id,
            'payable_type' => ProjectMilestone::class,
            'payable_id' => $milestone->id,
            'title' => "{$project->name}: {$milestone->title}",
            'po_number' => $project->po_number,
            'description' => 'Raised when the milestone was accepted.',
            'due_on' => today()->addDays((int) config('company.billing.milestone_due_days', 7)),
            'raised_by' => $signoff->requested_by,
        ]);

        $payment->price((int) $milestone->payment_amount, $this->invoicer->defaultTaxRate())->save();
        $this->invoicer->issueFor($payment);

        $signoff->forceFill(['payment_request_id' => $payment->id])->save();

        return $payment->fresh(['invoice', 'user']);
    }

    /** @return Collection<int, User> The project's manager, whoever asked, and the owner. */
    protected function staffFor(Project $project, ?int $requestedBy): Collection
    {
        return User::query()
            ->where(fn ($query) => $query->where('is_owner', true)
                ->when($project->manager_id, fn ($inner) => $inner->orWhere('id', $project->manager_id))
                ->when($requestedBy, fn ($inner) => $inner->orWhere('id', $requestedBy)))
            ->get();
    }
}
