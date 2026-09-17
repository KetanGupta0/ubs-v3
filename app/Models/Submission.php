<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Submission extends Model
{
    public const STATUSES = ['draft', 'submitted', 'returned', 'evaluated'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'submitted', 'is_late' => false];

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
}
