<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A bug or a change reported against the staging build (CL13). */
class FeedbackItem extends Model
{
    public const KINDS = ['bug' => 'Something is broken', 'change' => 'Something to change'];

    public const STATUSES = ['new', 'acknowledged', 'in_progress', 'fixed', 'closed', 'declined'];

    /** Still needs work from us, or an answer from the client. */
    public const OPEN = ['new', 'acknowledged', 'in_progress', 'fixed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'screenshots' => 'array',
            'fixed_at' => 'datetime',
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

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** Every step and comment, oldest first. */
    public function events(): HasMany
    {
        return $this->hasMany(FeedbackEvent::class)->orderBy('id');
    }

    public function scopeForClient(Builder $query, User $account): Builder
    {
        return $query->where('client_id', $account->id);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? 'Feedback';
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'new' => 'Reported',
            'acknowledged' => 'Seen',
            'in_progress' => 'Being worked on',
            'fixed' => 'Fixed on staging, please check',
            'closed' => 'Done',
            'declined' => 'Not changing',
            default => ucfirst($status),
        };
    }
}
