<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request to accept a milestone, and the answer to it (CL9).
 *
 * The deliverables are copied onto the row when it is asked for, so what was
 * accepted stays what was listed, whatever the milestone says later. An
 * answer is never changed: another round is another row.
 */
class MilestoneSignoff extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'deliverables' => 'array',
            'decided_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'milestone_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    /** Waiting for the client's answer. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('decision')->whereNull('withdrawn_at');
    }

    public function isPending(): bool
    {
        return $this->decision === null && $this->withdrawn_at === null;
    }

    /** waiting, accepted, changes or withdrawn: what the screens show. */
    public function state(): string
    {
        return match (true) {
            $this->withdrawn_at !== null => 'withdrawn',
            $this->decision === 'accepted' => 'accepted',
            $this->decision === 'rejected' => 'changes',
            default => 'waiting',
        };
    }

    /** @return array<string, mixed> */
    public function toScreen(): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state(),
            'deliverables' => $this->deliverables ?? [],
            'note' => $this->note,
            'requestedAt' => $this->created_at->format('j M Y, g:i a'),
            'requestedBy' => $this->requester?->name,
            'decidedAt' => $this->decided_at?->format('j M Y, g:i a'),
            'decidedBy' => $this->decider?->name,
            'comments' => $this->comments,
            'withdrawnAt' => $this->withdrawn_at?->format('j M Y, g:i a'),
        ];
    }
}
