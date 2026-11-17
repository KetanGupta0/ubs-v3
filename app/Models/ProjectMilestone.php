<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

class ProjectMilestone extends Model
{
    protected $guarded = ['id'];

    /** Once the client has accepted it, what they accepted stays put (CL9). */
    public const FROZEN_ONCE_ACCEPTED = ['title', 'description', 'payment_amount', 'completed_at', 'accepted_at', 'project_id'];

    protected static function booted(): void
    {
        static::updating(function (ProjectMilestone $milestone) {
            if ($milestone->getOriginal('accepted_at') !== null && $milestone->isDirty(self::FROZEN_ONCE_ACCEPTED)) {
                throw new LogicException('An accepted milestone cannot be changed.');
            }
        });

        static::deleting(function (ProjectMilestone $milestone) {
            if ($milestone->accepted_at !== null || $milestone->signoffs()->whereNotNull('decision')->exists()) {
                throw new LogicException('A milestone the client has answered cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'progress_percent' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Every request to accept it, oldest first. */
    public function signoffs(): HasMany
    {
        return $this->hasMany(MilestoneSignoff::class, 'milestone_id')->orderBy('id');
    }

    /** Payment requests raised for it, by acceptance or by hand. */
    public function paymentRequests(): MorphMany
    {
        return $this->morphMany(PaymentRequest::class, 'payable');
    }

    /** The one live invoice for it, if any: cancelled ones do not count. */
    public function billedRequest(): ?PaymentRequest
    {
        return $this->paymentRequests()->where('status', '!=', 'cancelled')->with('invoice')->latest('id')->first();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * Where acceptance stands: null if never asked (or the last request was
     * withdrawn), else waiting, changes or accepted. Reads loaded signoffs.
     */
    public function signoffState(): ?string
    {
        $last = $this->signoffs->last();

        return $last === null || $last->state() === 'withdrawn' ? null : $last->state();
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isComplete() && $this->due_date !== null && $this->due_date->isPast();
    }
}
