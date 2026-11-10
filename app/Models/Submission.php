<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Submission extends Model
{
    public const STATUSES = ['draft', 'submitted', 'returned', 'evaluated'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'submitted', 'is_late' => false, 'version' => 1];

    protected function casts(): array
    {
        return [
            'files' => 'array',
            'submitted_at' => 'datetime',
            'evaluated_at' => 'datetime',
            'is_late' => 'boolean',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }

    public function scopeAwaitingMarking(Builder $query): Builder
    {
        return $query->where('status', 'submitted')->whereNull('marks');
    }

    public function isMarked(): bool
    {
        return $this->marks !== null;
    }

    public function percent(): ?float
    {
        $max = $this->assignment?->max_marks;

        return $this->marks === null || ! $max ? null : round($this->marks / $max * 100, 1);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SubmissionVersion::class)->orderByDesc('version');
    }

    /**
     * Whether the student may replace this with a new version themselves.
     *
     * Before it is marked, yes, and after it has been sent back for another
     * go. Once it has been marked, no: otherwise a student could wipe their
     * own mark by handing the same work in again. Staff send it back first.
     */
    public function canBeReplacedByStudent(): bool
    {
        return in_array($this->status, ['draft', 'submitted', 'returned'], true);
    }

    /** Keep this version as it stands, feedback and mark included, before it is replaced. */
    public function archiveCurrentVersion(): SubmissionVersion
    {
        return $this->versions()->create([
            'version' => $this->version ?? 1,
            'notes' => $this->notes,
            'repository_url' => $this->repository_url,
            'demo_url' => $this->demo_url,
            'files' => $this->files,
            'submitted_at' => $this->submitted_at,
            'is_late' => $this->is_late,
            'status' => $this->status,
            'marks' => $this->marks,
            'feedback' => $this->feedback,
            'evaluated_by' => $this->evaluated_by,
            'evaluated_at' => $this->evaluated_at,
        ]);
    }
}
