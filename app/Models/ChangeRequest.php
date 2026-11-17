<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * A change to a project's scope (CL12): asked for, estimated, and approved
 * by signing the quotation addendum, or declined.
 *
 * Once quoted, what was asked and what we estimated are fixed, like any
 * sent document: a different estimate is a new request (`previous_id`).
 */
class ChangeRequest extends Model
{
    public const STATUSES = ['requested', 'quoted', 'approved', 'declined', 'withdrawn'];

    public const FROZEN = ['number', 'project_id', 'client_id', 'title', 'description', 'amount', 'days', 'estimate_note', 'parties', 'pdf_path', 'pdf_sha256', 'quoted_at'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (ChangeRequest $change) {
            if ($change->getOriginal('status') !== 'requested' && $change->isDirty(self::FROZEN)) {
                throw new LogicException('A quoted change request cannot be changed. Quote it again as a new request.');
            }
        });

        static::deleting(function (ChangeRequest $change) {
            if ($change->status !== 'requested') {
                throw new LogicException('A change request that was quoted is kept.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'days' => 'integer',
            'parties' => 'array',
            'quoted_at' => 'datetime',
            'viewed_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_id');
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function quoter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'quoted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewed_by');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class);
    }

    /** Signatures over the addendum as sent (CL2). */
    public function signatures(): MorphMany
    {
        return $this->morphMany(DocumentSignature::class, 'signable')->orderBy('signed_at');
    }

    public function scopeForClient(Builder $query, User $account): Builder
    {
        return $query->where('client_id', $account->id);
    }

    public function canBeAnswered(): bool
    {
        return $this->status === 'quoted';
    }

    public function amountLabel(): ?string
    {
        return $this->amount === null ? null : Money::display($this->amount);
    }

    public function daysLabel(): ?string
    {
        return match (true) {
            $this->days === null => null,
            $this->days === 0 => 'no change to the dates',
            $this->days === 1 => '1 more day',
            default => "{$this->days} more days",
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'requested' => 'Being estimated',
            'quoted' => 'Waiting for approval',
            'approved' => 'Approved',
            'declined' => 'Declined',
            'withdrawn' => 'Withdrawn',
            default => ucfirst($this->status),
        };
    }

    public static function nextNumber(): string
    {
        $year = now()->year;
        $last = static::query()->where('number', 'like', "UBS/CR/{$year}/%")->orderByDesc('id')->value('number');
        $next = $last ? ((int) str($last)->afterLast('/')->toString()) + 1 : 1;

        return sprintf('UBS/CR/%d/%03d', $year, $next);
    }
}
