<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something we need from the client to carry on (CL10).
 *
 * Open while it waits on them, answered once they reply, closed when we
 * have what we needed, or cancelled. One that blocks work counts towards
 * the time the project spent waiting on the client.
 */
class WaitingItem extends Model
{
    public const KINDS = [
        'assets' => 'Files or brand assets',
        'content' => 'Content or copy',
        'access' => 'Access to a system',
        'decision' => 'A decision',
        'information' => 'Information',
        'action' => 'Agreed in a meeting',
        'other' => 'Something else',
    ];

    public const STATUSES = ['open', 'answered', 'closed', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'blocks_work' => 'boolean',
            'answered_at' => 'datetime',
            'closed_at' => 'datetime',
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

    /** The meeting whose minutes it came from, if any (CL11). */
    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function answerer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    /** Every step, oldest first. */
    public function events(): HasMany
    {
        return $this->hasMany(WaitingItemEvent::class)->orderBy('id');
    }

    public function scopeForClient(Builder $query, User $account): Builder
    {
        return $query->where('client_id', $account->id);
    }

    /** Still waiting on the client. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_on !== null && $this->due_on->lt(today());
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? 'Something else';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'open' => 'Waiting on you',
            'answered' => 'Answered',
            'closed' => 'Done',
            'cancelled' => 'No longer needed',
            default => ucfirst($this->status),
        };
    }

    /**
     * Who in the client's organisation is asked: a decision goes to those who
     * may commit the organisation, access to its technical people, the rest
     * to everybody.
     */
    public function audience(): string
    {
        return match ($this->kind) {
            'decision' => 'signing',
            'access' => 'technical',
            default => 'all',
        };
    }
}
